<?php

namespace App\Support;

use App\Models\GoogleCalendarEvent;
use App\Models\GoogleCalendarLink;
use App\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Googleカレンダーへの直接登録（正本・2026-11-02 baba決定「ボタン1つでGoogleカレンダーに書き込む方式」）。
 *
 * 流れ：①マイページの「Googleカレンダーと連携」→ Googleで本人が「許可」（1回だけ）→ 鍵（refresh_token）を保存
 *       ②「Googleカレンダーに登録」→ MyCalendarEvents の予定を本人のメインのカレンダーへ書く。
 * 決まり：・同じ予定（uid が同じ）は上書きする＝何回押しても重ならない。Google側で消されていたら作り直す。
 *         ・アサインが外れた先の予定（ECSが登録したものだけ）は消す。過去の予定は触らない。
 *         ・ECSが登録していない予定（本人が自分で入れた予定）には一切触らない。
 * ⚠ 使えるのは .env に GOOGLE_CALENDAR_CLIENT_ID／SECRET が入っているときだけ（configured()）。
 */
final class GoogleCalendarSync
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    public static function configured(): bool
    {
        return (string) config('services.google_calendar.client_id') !== ''
            && (string) config('services.google_calendar.client_secret') !== '';
    }

    public static function redirectUri(): string
    {
        return url('/google-calendar/callback');
    }

    /** Googleの「許可」画面のURL。state＝なりすまし防止（セッションに覚えて、戻ってきたとき比べる）。 */
    public static function authUrl(string $state, string $email = ''): string
    {
        return self::AUTH_URL.'?'.http_build_query(array_filter([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email '.self::SCOPE,   // email＝どのGoogleアカウントにつないだかを画面に出す用
            'access_type' => 'offline',   // 鍵（refresh_token）をもらう＝次から「許可」を押さなくてよい
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => $email,
            'state' => $state,
        ]));
    }

    /** 戻ってきた code を鍵に替えて保存する。 */
    public static function connect(Person $me, string $code): GoogleCalendarLink
    {
        $res = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        $refresh = (string) $res->json('refresh_token', '');
        if (! $res->successful() || $refresh === '') {
            throw new RuntimeException('Googleから鍵をもらえませんでした（'.$res->status().'）');
        }

        return GoogleCalendarLink::updateOrCreate(['person_id' => $me->id], [
            'refresh_token' => $refresh,
            'google_email' => self::emailOf((string) $res->json('id_token', '')) ?: null,
        ]);
    }

    /** 連携をやめる（鍵をGoogleに返して、ECSからも消す）。登録した予定はカレンダーに残す。 */
    public static function disconnect(Person $me): void
    {
        $link = GoogleCalendarLink::find($me->id);
        if ($link) {
            try {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $link->refresh_token]);
            } catch (\Throwable $e) {
                // 返せなくてもECS側は消す（本人がGoogleのアカウント設定から外せる）。
            }
            $link->delete();
        }
        GoogleCalendarEvent::where('person_id', $me->id)->delete();
    }

    /**
     * 今日から先のアサインを本人のメインのカレンダーへ書く。
     *
     * @return array{created:int, updated:int, deleted:int, failed:int, skipped:int, cases:int}
     */
    public static function sync(Person $me, ?Carbon $today = null, string $dup = 'ask'): array
    {
        $link = GoogleCalendarLink::find($me->id);
        if (! $link) {
            throw new RuntimeException('Googleカレンダーと連携していません');
        }
        $token = self::accessToken($link);
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $events = MyCalendarEvents::for($me, $today);
        $known = GoogleCalendarEvent::where('person_id', $me->id)->get()->keyBy('uid');
        $count = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0, 'skipped' => 0,
            'cases' => count(array_filter($events, fn ($e) => str_ends_with($e['uid'], '-main')))];   // 案件の数＝本体の予定の数

        // すでにカレンダーにある「ECSが入れたのではない予定」（アサイン表のGAS・本人の手入力）の時刻。
        // 始まりと終わりがぴったり同じ予定があれば、ECSからは入れない＝二重にしない（2026-11-13 小沼さん）。
        $bodies = [];
        foreach ($events as $e) {
            $bodies[$e['uid']] = self::body($e);
        }
        $foreign = self::foreignSlots($token, $bodies);
        // かぶりをどうするかは本人が決める（2026-11-13 小沼さん）。ask＝かぶりがあれば何も書かずに聞き返す／skip＝飛ばす／add＝かぶっても入れる。
        if ($dup === 'ask') {
            // 件数は案件で数える（移動・前泊・後泊だけがかぶっていても、その案件を1件として見せる）。見せる名前は本体の予定の題名。
            $hitCases = [];
            foreach ($events as $e) {
                if (isset($foreign[self::slotKey($bodies[$e['uid']])])) {
                    $hitCases[self::caseOf($e['uid'])] = true;
                }
            }
            if ($hitCases) {
                $titles = [];
                foreach ($events as $e) {
                    if (str_ends_with($e['uid'], '-main') && isset($hitCases[self::caseOf($e['uid'])]) && count($titles) < 5) {
                        $titles[] = ($e['day'] ?? $e['start'])->format('n/j').' '.$e['title'];
                    }
                }

                return $count + ['ask' => true, 'overlaps' => count($hitCases), 'titles' => $titles];
            }
        }
        if ($dup === 'add') {
            $foreign = [];
        }

        $seen = [];
        $done = [];   // 予定ごとの結果（uid => created / updated / skipped / failed）
        $gone = [];   // 予定を消した案件
        foreach ($events as $e) {
            $seen[$e['uid']] = true;
            $body = $bodies[$e['uid']];
            $row = $known->get($e['uid']);
            if (isset($foreign[self::slotKey($body)])) {
                // 前にECSが入れていた分は消す（二重になった分の片づけ）。⚠ 相手の予定（ECSが入れていない方）には触らない。
                if ($row) {
                    $res = Http::withToken($token)->delete(self::EVENTS_URL.'/'.rawurlencode($row->event_id));
                    if (! $res->successful() && ! in_array($res->status(), [404, 410], true)) {
                        $done[$e['uid']] = 'failed';

                        continue;
                    }
                    $row->delete();
                }
                $done[$e['uid']] = 'skipped';

                continue;
            }
            if ($row) {
                $res = Http::withToken($token)->put(self::EVENTS_URL.'/'.rawurlencode($row->event_id), $body);
                if ($res->successful()) {
                    $done[$e['uid']] = 'updated';
                    $row->update(['case_date' => $e['caseDate']]);

                    continue;
                }
                if (! in_array($res->status(), [404, 410], true)) {
                    $done[$e['uid']] = 'failed';

                    continue;
                }
                // Google側で消されていた → 作り直す
            }
            $res = Http::withToken($token)->post(self::EVENTS_URL, $body);
            if ($res->successful() && $res->json('id')) {
                $done[$e['uid']] = 'created';
                GoogleCalendarEvent::updateOrCreate(['person_id' => $me->id, 'uid' => $e['uid']],
                    ['event_id' => (string) $res->json('id'), 'case_date' => $e['caseDate']]);
            } else {
                $done[$e['uid']] = 'failed';
            }
        }

        // アサインが外れた（または中止・日付が変わった）先の予定を消す。⚠ 過去の予定・ECSが登録していない予定は触らない。
        foreach ($known as $uid => $row) {
            if (isset($seen[$uid]) || $row->case_date->lt($today)) {
                continue;
            }
            $res = Http::withToken($token)->delete(self::EVENTS_URL.'/'.rawurlencode($row->event_id));
            if ($res->successful() || in_array($res->status(), [404, 410], true)) {
                $gone[self::caseOf($uid)] = true;
                $row->delete();
            } else {
                $done[$uid] = 'failed';
            }
        }
        // 数は案件で数える（前後の「移動」や前泊・後泊は案件に付いているものなので、別に数えない・2026-11-13 小沼さん）。
        // 案件の結果＝本体の予定（-main）の結果。どれか1つでも入らなければ「入らなかった」。
        $byCase = [];
        foreach ($done as $uid => $st) {
            $c = self::caseOf($uid);
            if ($st === 'failed' || ! isset($byCase[$c]) || (str_ends_with($uid, '-main') && $byCase[$c] !== 'failed')) {
                $byCase[$c] = $st;
            }
        }
        foreach ($byCase as $st) {
            $count[$st]++;
        }
        $count['deleted'] = count($gone);
        $link->update(['synced_at' => Carbon::now()]);

        return $count;
    }

    /**
     * これから入れる予定の期間にある「ECSが入れたのではない予定」の時刻の一覧（slotKey の形）。
     * ECSが入れた予定は extendedProperties.private.ecsUid の印で見分ける。読めなかったときは空＝今までどおり全部入れる。
     *
     * @param  array<string, array<string, mixed>>  $bodies
     * @return array<string, true>
     */
    private static function foreignSlots(string $token, array $bodies): array
    {
        $min = null;
        $max = null;
        foreach ($bodies as $b) {
            $s = Carbon::parse($b['start']['dateTime'] ?? $b['start']['date'].' 00:00:00', 'Asia/Tokyo');
            $e = Carbon::parse($b['end']['dateTime'] ?? $b['end']['date'].' 00:00:00', 'Asia/Tokyo');
            $min = $min === null || $s->lt($min) ? $s : $min;
            $max = $max === null || $e->gt($max) ? $e : $max;
        }
        if ($min === null) {
            return [];
        }
        $slots = [];
        $page = null;
        for ($i = 0; $i < 20; $i++) {   // 1回2500件まで。念のため20ページで打ち切る
            $res = Http::withToken($token)->get(self::EVENTS_URL, array_filter([
                'timeMin' => $min->copy()->subDay()->toRfc3339String(),
                'timeMax' => $max->copy()->addDay()->toRfc3339String(),
                'singleEvents' => 'true',
                'maxResults' => 2500,
                'pageToken' => $page,
            ]));
            if (! $res->successful()) {
                return [];
            }
            foreach ((array) $res->json('items', []) as $it) {
                if (($it['extendedProperties']['private']['ecsUid'] ?? '') !== '' || ! isset($it['start'], $it['end'])) {
                    continue;
                }
                $slots[self::slotKey($it)] = true;
            }
            $page = $res->json('nextPageToken');
            if (! $page) {
                break;
            }
        }

        return $slots;
    }

    /** 予定の名前（uid＝案件ID-役割-種類）から、案件のぶん（案件ID-役割）だけを取り出す。 */
    private static function caseOf(string $uid): string
    {
        return (string) preg_replace('/-(move-before|main|move-after|stay-pre|stay-post)$/', '', $uid);
    }

    /** 予定の「始まり|終わり」。時刻つきは時間帯の書き方が違っても同じになるよう秒に直す。終日は日付のまま。 */
    private static function slotKey(array $ev): string
    {
        $one = fn (array $p) => isset($p['dateTime']) ? (string) Carbon::parse($p['dateTime'])->getTimestamp() : 'd'.($p['date'] ?? '');

        return $one($ev['start']).'|'.$one($ev['end']);
    }

    /** 鍵（refresh_token）から、その場で使う通行証（access_token）をもらう。 */
    private static function accessToken(GoogleCalendarLink $link): string
    {
        $res = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $link->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $token = (string) $res->json('access_token', '');
        if (! $res->successful() || $token === '') {
            // 鍵が使えない（本人がGoogle側で許可を外した等）＝もう一度「連携」してもらう。
            throw new RuntimeException('Googleとの連携が切れています。もう一度「Googleカレンダーと連携」を押してください');
        }

        return $token;
    }

    /** MyCalendarEvents の1件 → Googleカレンダーの予定の形。 */
    private static function body(array $e): array
    {
        $tz = 'Asia/Tokyo';
        $when = $e['day']
            ? ['start' => ['date' => $e['day']->format('Y-m-d')], 'end' => ['date' => $e['day']->copy()->addDay()->format('Y-m-d')]]
            : ['start' => ['dateTime' => $e['start']->copy()->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz],
                'end' => ['dateTime' => $e['end']->copy()->setTimezone($tz)->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz]];

        return $when + [
            'summary' => $e['title'],
            'description' => $e['desc'],
            'location' => $e['loc'],
            'extendedProperties' => ['private' => ['ecsUid' => $e['uid']]],
        ];
    }

    /** id_token（Googleが返す本人情報）からメールアドレスだけ読む。署名は確かめない＝表示用だけに使う。 */
    private static function emailOf(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return '';
        }
        $json = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($json) ? (string) ($json['email'] ?? '') : '';
    }
}
