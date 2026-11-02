<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 自分のアサインをカレンダーの予定にする（正本・2026-11-02 小沼さん）。
 *
 * 【形】小沼さんがアサイン表のGASで使っているものに合わせた：
 *   移動（集合の1時間前〜集合）／【役割】_コンテンツ_顧客（集合〜解散）／移動（解散〜解散の1時間後）
 *   ＋前泊（前の日・前泊の集合時間があればその時間〜23:59、無ければ終日）・後泊（次の日・終日）。
 *   集合・解散の時間が決まっていない案件は、本体だけ終日（移動は入れない）。夜をまたぐ解散は次の日。
 * 【使う所】マイページの「Googleカレンダーに登録」（GoogleCalendarSync）と、予定のファイル（.ics）の両方。
 *   ⚠ 画面（JS）に同じ組み立てを書かない＝2か所あると片方だけ直して食い違う。
 * 【uid】予定ごとに決まった名前（案件ID-役割-種類）。もう一度登録しても同じ予定として上書きする目印。
 */
final class MyCalendarEvents
{
    /**
     * 今日から先の自分のアサイン（キャンセル・下書き・キャンセル案件は除く）の予定。
     *
     * @return list<array{uid:string, title:string, desc:string, loc:string, start:?Carbon, end:?Carbon, day:?Carbon, caseDate:string}>
     */
    public static function for(Person $me, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $roles = Assignment::where('staff_id', $me->id)->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'role'])->mapWithKeys(fn ($a) => [$a->project_id => $a->role ?: '現場'])->all();
        if ($roles === []) {
            return [];
        }
        $contentNames = Content::pluck('content_name', 'id');
        $projects = Project::with('director:id,name')->whereIn('id', array_keys($roles))
            ->notCancelled()
            ->whereDate('start_date', '>=', $today->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', '下書き'))
            ->orderBy('start_date')->get();

        $out = [];
        foreach ($projects as $p) {
            $out = array_merge($out, self::ofProject($p, (string) $roles[$p->id], ProjectContentName::of($p, $contentNames)));
        }

        return $out;
    }

    /** 案件1件ぶんの予定。 */
    public static function ofProject(Project $p, string $role, string $content): array
    {
        $date = $p->start_date->copy()->startOfDay();
        $client = (string) ($p->client ?? '');
        $label = AssignmentRole::label($role);
        $title = '【'.preg_replace('/（.*）$/u', '', $label).'】_'.$content.'_'.$client;
        $loc = trim((string) ($p->location ?? ''));
        $sales = is_array($p->sales_owners) ? (string) ($p->sales_owners[0] ?? '') : '';
        $desc = '顧客：'.$client."\nコンテンツ：".$content."\nポジション：".$label
            ."\n営業担当：".($sales ?: '—')."\nディレクター：".($p->director->name ?? '—')
            .($loc !== '' ? "\n会場：".$loc : '').(trim((string) $p->note) !== '' ? "\nメモ：".trim((string) $p->note) : '')
            ."\n（ECSから登録・".$p->id.'）';
        $uid = $p->id.'-'.$role;
        $caseDate = $date->format('Y-m-d');
        $ev = fn (array $a) => $a + ['loc' => '', 'start' => null, 'end' => null, 'day' => null, 'caseDate' => $caseDate];

        $out = [];
        $mt = self::hm($p->start_time);
        $lt = self::hm($p->end_time);
        if ($mt && $lt) {
            $s = $date->copy()->setTime($mt[0], $mt[1]);
            $e = $date->copy()->setTime($lt[0], $lt[1]);
            if ($e->lte($s)) {
                $e->addDay();   // 日をまたぐ解散
            }
            $out[] = $ev(['uid' => $uid.'-move-before', 'title' => '移動', 'desc' => $title.' への移動', 'start' => $s->copy()->subHour(), 'end' => $s->copy()]);
            $out[] = $ev(['uid' => $uid.'-main', 'title' => $title, 'desc' => $desc, 'loc' => $loc, 'start' => $s, 'end' => $e]);
            $out[] = $ev(['uid' => $uid.'-move-after', 'title' => '移動', 'desc' => $title.' からの移動', 'start' => $e->copy(), 'end' => $e->copy()->addHour()]);
        } else {
            $out[] = $ev(['uid' => $uid.'-main', 'title' => $title, 'desc' => $desc."\n（集合・解散の時間が未定のため終日で登録）", 'loc' => $loc, 'day' => $date->copy()]);
        }

        $partial = str_contains((string) $p->lodging, '一部') ? "\n※一部前泊＝自分が泊まるかは担当に確認してください" : '';
        $stayTitle = fn ($k) => $k.'｜'.$content.'（'.$client.'）';
        if (Lodging::hasPreStay($p->lodging)) {
            $day = $date->copy()->subDay();
            $pt = self::hm($p->stay_pre_meet_time);
            $base = ['uid' => $uid.'-stay-pre', 'title' => $stayTitle('前泊'), 'loc' => $loc, 'desc' => '次の日が本番：'.$title.$partial];
            $out[] = $pt
                ? $ev($base + ['start' => $day->copy()->setTime($pt[0], $pt[1]), 'end' => $day->copy()->setTime(23, 59)])
                : $ev($base + ['day' => $day]);
        }
        if (Lodging::hasPostStay($p->lodging)) {
            $out[] = $ev(['uid' => $uid.'-stay-post', 'title' => $stayTitle('後泊'), 'loc' => $loc, 'desc' => '前の日が本番：'.$title, 'day' => $date->copy()->addDay()]);
        }

        return $out;
    }

    /** 「9:00」「07:00」「9時30」→ [時, 分]。読めなければ null。 */
    public static function hm($t): ?array
    {
        if (! preg_match('/(\d{1,2})\s*[:：時]\s*(\d{1,2})?/u', (string) $t, $m) || (int) $m[1] > 23) {
            return null;
        }

        return [(int) $m[1], (int) ($m[2] ?? 0)];
    }

    /** 予定のファイル（.ics）。Googleカレンダーの「インポート」で一度に入れられる（予備の道）。 */
    public static function ics(array $events): string
    {
        $esc = fn ($s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], (string) $s);
        $utc = fn (Carbon $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//IKUSA//ECS//JA', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
        $stamp = $utc(Carbon::now());
        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['uid'].'@ecs.ikusa.co.jp';
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($e['day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e['day']->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e['day']->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$utc($e['start']);
                $lines[] = 'DTEND:'.$utc($e['end']);
            }
            $lines[] = 'SUMMARY:'.$esc($e['title']);
            $lines[] = 'DESCRIPTION:'.$esc($e['desc']);
            if ($e['loc'] !== '') {
                $lines[] = 'LOCATION:'.$esc($e['loc']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    /** 長い行は75バイトごとに折り返す（.ics の決まり）。日本語・絵文字を途中で切らない。 */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $cur = '';
        $limit = 75;
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) {
                $parts[] = $cur;
                $cur = '';
                $limit = 74;
            }
            $cur .= $ch;
        }
        $parts[] = $cur;

        return implode("\r\n ", $parts);
    }
}
