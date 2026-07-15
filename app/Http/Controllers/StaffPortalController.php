<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Models\Setting;
use App\Models\ShiftPreference;
use App\Models\StaffRoleEligibility;
use App\Support\AssignmentRole;
use App\Support\TestAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * スタッフ側ポータル（/staff-portal）。
 *
 * 「確定アサイン」タブと、稼働希望カレンダーの「イベント（確定）」表示を
 * DB の projects テーブルから作る。これまではブラウザの localStorage
 * （ecs_publish_<id>）を見ていたため、公開の状態が同じブラウザの中でしか
 * 共有されなかった。ここでは公開の「背骨」である staff_published 列を読み、
 * 担当が公開ボード（/assign-publish）で公開ONにした案件だけをスタッフに見せる。
 *
 * ※ 募集中タブ（エントリー）は応募フロー側の話なので、ここでは触らない
 *   （画面側は従来どおり cases.js を読む）。ここで渡すのは「公開済み案件」だけ。
 */
class StaffPortalController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // ログイン本人（people名簿の1行）。本人の確定アサインだけを見せるために使う。
        $me = Auth::user();

        // 本人のアサイン（キャンセル除外＝他画面と同じ絞り）を「案件ID → 行」で引く。
        // 同一案件に複数役割の行があれば keyBy で後勝ち（1つに代表させる）。
        $mine = $me
            ? Assignment::where('staff_id', $me->id)
                ->where('status', '!=', 'キャンセル')
                ->get()
                ->keyBy('project_id')
            : collect();

        // 公開ON の案件だけを開催日の近い順に取り出す。
        // このあと「本人がアサインされた案件だけ」に絞る＝スタッフ画面はあくまで
        // 「あなたの確定アサイン」なので、公開中でも本人が入っていない案件は見せない
        // （誰でも全案件が見えてしまうのを防ぎ、本人の担当だけを表示するため）。
        $published = Project::where('staff_published', true)
            ->orderBy('start_date')
            ->get()
            ->map(function (Project $p) use ($today, $mine) {
                // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。画面が日付計算に使う。
                $off = $p->start_date
                    ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                    : 0;

                return [
                    'id'        => $p->id,
                    'content'   => $p->project_name,
                    'client'    => $p->client ?? '',
                    'place'     => $p->location ?? '',
                    'meetPlace' => $p->assembly_type ?? '',
                    // スタッフ向けの集合・解散時間。担当が公開ボードで直していれば優先、無ければ社員の時間。
                    'meet'      => $p->staff_meet_time ?? $p->start_time ?? '—',
                    'leave'     => $p->staff_leave_time ?? $p->end_time ?? '—',
                    'off'       => $off,
                    // 本人がこの案件にアサインされているか／本人の担当ポジション（表示名）。
                    // 役割コード→表示名は必ず AssignmentRole::label() を使う（日本語直書き禁止＝表記ゆれ防止）。
                    'mine'      => $mine->has($p->id),
                    'myRole'    => $mine->has($p->id) ? AssignmentRole::label(optional($mine->get($p->id))->role) : '',
                ];
            })
            // 本人がアサインされた案件だけに絞る＝「公開ON かつ 自分がアサイン済み」だけが
            // 本当の『あなたの確定アサイン』。
            ->filter(fn ($c) => $c['mine'])
            ->values();

        return view('staff_portal', [
            'published' => $published,
            'recruitJobs' => $this->recruitJobs($today),
            'notice' => Setting::get('staff_notice', ''),   // スタッフ画面のお知らせ文（DB保存）
            'myProfile' => $this->myProfile($me),           // 設定タブの初期表示（本人のDB値）
            'prefPeriod' => self::PREF_PERIOD,              // 稼働希望カレンダーの対象月（画面は7月固定）
            'myPrefs' => $this->myPrefs($me),               // 本人の希望（カレンダー初期表示）
            'myPrefMemo' => $this->myPrefMemo($me),         // 希望のコメント（初期表示）
        ]);
    }

    // 稼働希望カレンダーの対象月。画面（cal-grid）が 2026年7月 固定で組まれているのに合わせる。
    // ※月の切り替えは既存カレンダーの作り込み範囲外。ここでは「その月の保存・読込」を本物にする。
    private const PREF_PERIOD = '2026-07';

    /** DBの availability → カレンダーの状態語（ok/ng/maybe）。「希望」も画面では〇(ok)扱い。 */
    private const PREF_TO_VIEW = ['稼働可' => 'ok', '希望' => 'ok', 'NG' => 'ng', '未定' => 'maybe'];

    /**
     * 設定タブ（プロフィール／できるポジション・スキル）の初期表示に使う、本人のDB値。
     * テスト用アカウント（DBに実体が無い）や未ログインのときは null＝画面は空欄で開く。
     */
    private function myProfile($me): ?array
    {
        if (! $me || TestAccounts::isTest($me)) {
            return null;
        }

        $elig = $me->roleEligibilities->pluck('position')->all();

        return [
            'height'            => $me->height,
            'shoe_size'         => $me->shoe_size,
            'shirt_size'        => $me->shirt_size,
            'prefecture'        => $me->prefecture,
            'nearest_station'   => $me->nearest_station,
            'appeal'            => $me->appeal,
            'liked_contents'    => $me->liked_contents,
            'disliked_contents' => $me->disliked_contents,
            'strong_positions'  => $me->strong_positions,
            'weak_positions'    => $me->weak_positions,
            'mcPass'            => (bool) $me->mc_audition_passed,
            'kigurumi'          => (bool) $me->can_kigurumi,
            'stay'              => (bool) $me->can_stay_over,
            'drive'             => $me->driving_level,
            'english'           => $me->english_level,
            // OP・軍師(SP) は「できる役割」= staff_role_eligibility。トグルの初期ON/OFFに使う。
            'op'                => in_array(AssignmentRole::OP, $elig, true),
            'gunshi'            => in_array(AssignmentRole::SP, $elig, true),
        ];
    }

    /**
     * 設定タブ「プロフィール（自分の情報）」をDB(people)へ保存（AJAX・本人分のみ）。
     * これまで localStorage 保存だったものを本物化。テスト用アカウントは保存しない（/profile と同じ方針）。
     */
    public function saveProfile(Request $request)
    {
        $user = Auth::user();
        if (! $user || TestAccounts::isTest($user)) {
            return response()->json(['ok' => false, 'message' => 'テスト用アカウントは保存できません（見本のため）。']);
        }

        $data = $request->validate([
            'height'            => ['nullable', 'string', 'max:20'],
            'shoe_size'         => ['nullable', 'string', 'max:20'],
            'shirt_size'        => ['nullable', 'string', 'max:20'],
            'prefecture'        => ['nullable', 'string', 'max:20'],
            'nearest_station'   => ['nullable', 'string', 'max:100'],
            'appeal'            => ['nullable', 'string', 'max:1000'],
            'liked_contents'    => ['nullable', 'string', 'max:1000'],
            'disliked_contents' => ['nullable', 'string', 'max:1000'],
            'strong_positions'  => ['nullable', 'string', 'max:1000'],
            'weak_positions'    => ['nullable', 'string', 'max:1000'],
        ]);

        $user->fill($data);   // Person は guarded=[] なので列名一致でそのまま入る
        $user->save();

        return response()->json(['ok' => true, 'message' => 'プロフィールを保存しました。']);
    }

    /**
     * 設定タブ「できるポジション・スキル」をDBへ保存（AJAX・本人分のみ）。
     * MC合格・着ぐるみ・前泊・運転・英語は people 列へ。OP・軍師(SP) は staff_role_eligibility。
     * OP/SP は「この2つの範囲だけ」入れ替える（範囲外＝管理者が名簿で付けた他の役割は温存）。
     */
    public function savePositions(Request $request)
    {
        $user = Auth::user();
        if (! $user || TestAccounts::isTest($user)) {
            return response()->json(['ok' => false, 'message' => 'テスト用アカウントは保存できません（見本のため）。']);
        }

        $user->mc_audition_passed = $request->boolean('mc');
        $user->can_kigurumi       = $request->boolean('kigurumi');
        $user->can_stay_over      = $request->boolean('stay');
        $drive   = trim((string) $request->input('drive', ''));
        $english = trim((string) $request->input('english', ''));
        $user->driving_level = ($drive === '' || $drive === '（なし）') ? null : $drive;
        $user->english_level = ($english === '' || $english === '（なし）') ? null : $english;
        $user->save();

        // OP・軍師(SP) の「できる役割」を、この2つの範囲だけ入れ替える（他の役割は消さない）。
        $want = [];
        if ($request->boolean('op')) {
            $want[] = AssignmentRole::OP;
        }
        if ($request->boolean('gunshi')) {
            $want[] = AssignmentRole::SP;
        }
        DB::transaction(function () use ($user, $want) {
            StaffRoleEligibility::where('staff_id', $user->id)
                ->whereIn('position', [AssignmentRole::OP, AssignmentRole::SP])
                ->delete();
            foreach ($want as $pos) {
                StaffRoleEligibility::create(['staff_id' => $user->id, 'position' => $pos]);
            }
        });

        return response()->json(['ok' => true, 'message' => '保存しました。']);
    }

    /**
     * 稼働希望カレンダーの初期表示に使う、本人の希望（date "Y-M-D" => ok/ng/maybe）。
     * 画面のキーは月/日ゼロ埋めなし（employee-availability と同じ形）。テスト/未ログインは空。
     */
    private function myPrefs($me): array
    {
        if (! $me || TestAccounts::isTest($me)) {
            return [];
        }
        $map = [];
        foreach (ShiftPreference::where('staff_id', $me->id)->get() as $sp) {
            $d = $sp->date;
            if (! $d) {
                continue;
            }
            $view = self::PREF_TO_VIEW[$sp->availability] ?? null;
            if ($view !== null) {
                $map[$d->year.'-'.$d->month.'-'.$d->day] = $view;
            }
        }
        return $map;
    }

    /** 希望のコメント（対象月の note を1つ拾う）。 */
    private function myPrefMemo($me): string
    {
        if (! $me || TestAccounts::isTest($me)) {
            return '';
        }
        $row = ShiftPreference::where('staff_id', $me->id)
            ->where('period', self::PREF_PERIOD)
            ->whereNotNull('note')
            ->where('note', '!=', '')
            ->first();

        return $row->note ?? '';
    }

    /**
     * 稼働希望カレンダーの提出（POST /staff-portal/availability・本人分のみ）。
     * その月ぶんを「作り直す」＝本人×対象月の既存行を消してから、ok(稼働可)/ng(NG) だけ入れ直す。
     * 未定は行を持たない（＝希望なし）。テスト用アカウントは保存しない。
     */
    public function saveAvailability(Request $request)
    {
        $user = Auth::user();
        if (! $user || TestAccounts::isTest($user)) {
            return response()->json(['ok' => false, 'message' => 'テスト用アカウントは保存できません（見本のため）。']);
        }

        $period = (string) $request->input('period', '');
        $state = (array) $request->input('state', []);   // { "Y-M-D": "ok"|"ng"|"maybe" }
        $memo = $request->input('memo');

        // period（YYYY-MM）から対象の年・月を取り出す（その月を作り直す範囲）。
        if (! preg_match('/^(\d{4})-(\d{1,2})$/', $period, $mm)) {
            return response()->json(['ok' => false, 'message' => 'period（対象月）が不正です。'], 422);
        }
        $year = (int) $mm[1];
        $month = (int) $mm[2];

        $toDb = ['ok' => '稼働可', 'ng' => 'NG'];   // 未定は保存しない（行なし＝希望なし）

        $saved = DB::transaction(function () use ($user, $year, $month, $period, $state, $memo, $toDb) {
            // 本人×対象月の希望を一旦消す（未定に戻した日も確実に消えるように）。
            ShiftPreference::where('staff_id', $user->id)
                ->whereYear('date', $year)
                ->whereMonth('date', $month)
                ->delete();

            $n = 0;
            foreach ($state as $key => $value) {
                $availability = $toDb[$value] ?? null;
                if ($availability === null) {
                    continue;   // maybe（未定）や不正値は入れない
                }
                $parts = explode('-', (string) $key);
                if (count($parts) !== 3) {
                    continue;
                }
                try {
                    $date = Carbon::create((int) $parts[0], (int) $parts[1], (int) $parts[2])->format('Y-m-d');
                } catch (\Throwable $e) {
                    continue;
                }
                ShiftPreference::create([
                    'staff_id' => $user->id,
                    'period' => $period,
                    'date' => $date,
                    'availability' => $availability,
                    'note' => $memo,
                ]);
                $n++;
            }
            return $n;
        });

        return response()->json(['ok' => true, 'saved' => $saved]);
    }

    /**
     * 募集中タブに出す案件リスト（projects から）。
     * 「募集する・完了/下書きでない」案件を、画面の jobs 変換がそのまま読める項目名で返す。
     * ※「あなたがエントリー中か」は本人特定（ログイン）が要るため、画面側の従来モックのまま
     *   （ここでは案件リストだけを本物にする。状態は満員→締切／それ以外→募集中）。
     */
    private function recruitJobs(Carbon $today)
    {
        $projects = Project::where('is_recruiting', true)
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get();

        if ($projects->isEmpty()) {
            return collect();
        }

        // 充足数＝確定/仮アサイン（キャンセル除く）の人数。案件ごとにまとめて数える。
        $filledByProject = Assignment::whereIn('project_id', $projects->pluck('id'))
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->count());

        $contentNames = Content::pluck('content_name', 'id');

        // 通常案件の締切＝全体で1つの「一斉締切日」（settings）。追加案件は下の deadlineLabel で個別計算。
        $bulkDeadline = trim((string) Setting::get('entry_deadline', ''));

        return $projects->map(function (Project $p) use ($today, $filledByProject, $contentNames, $bulkDeadline) {
            $off = $p->start_date
                ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp, 86400)
                : 0;

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? $p->project_name) : $p->project_name;

            $format = (string) ($p->format ?? '');
            $fmt = '';
            if (mb_strpos($format, 'オンライン') !== false) {
                $fmt = 'online';
            } elseif (mb_strpos($format, 'ロング') !== false) {
                $fmt = 'long';
            } elseif (mb_strpos($format, 'リアル') !== false) {
                $fmt = 'real';
            }

            // 画面の jobs 変換（staff_portal.blade）がそのまま読める項目名で返す。
            return [
                'id' => $p->id,
                'content' => $content,
                'client' => $p->client ?? '',
                'place' => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'area' => $p->operation_place ?? '',
                'fmt' => $fmt,
                'scale' => $p->scale ?? '',
                'repeat' => (bool) $p->is_repeat,
                'lodging' => $p->lodging ?? '無',
                'dayType' => $p->date_type ?? '本番',
                'parentId' => $p->parent_project_id,
                'off' => $off,
                'need' => $p->required_count ?? 0,
                'filled' => $filledByProject->get($p->id, 0),
                'meet' => $p->start_time ?? '—',
                'leave' => $p->end_time ?? '—',
                'enter' => $p->event_enter_time ?? '—',
                'evStart' => $p->event_start_time ?? '—',
                'evEnd' => $p->event_end_time ?? '—',
                'category' => $p->category ?? '通常案件',
                'deadline' => $this->deadlineLabel($p, $bulkDeadline),
                'recruit' => true,
                'archived' => $off < 0,   // 過去のイベントは募集タブに出さない
                'draft' => false,
            ];
        })->values();
    }

    /**
     * スタッフ画面に出す「締切」の表示ラベル（例 "7/5"）。締切は表示だけ＝過ぎても応募は受け付ける。
     *  - 通常案件＝全体で1つの一斉締切日（未設定なら空文字＝表示しない）。
     *  - 追加案件＝公開した日（extra_published_at・無ければ登録日）＋3日。その日が土日なら月曜にずらす。
     */
    private function deadlineLabel(Project $p, string $bulkDeadline): string
    {
        if (($p->category ?? '通常案件') === '追加案件') {
            $base = $p->extra_published_at ?? $p->created_at;
            if (!$base) {
                return '';
            }
            $d = Carbon::parse($base)->startOfDay()->addDays(3);
            if ($d->isSaturday()) {
                $d->addDays(2);   // 土曜 → 月曜
            } elseif ($d->isSunday()) {
                $d->addDay();     // 日曜 → 月曜
            }

            return $d->format('n/j');
        }

        // 通常案件＝一斉締切日（未設定なら空＝チップを出さない）。
        if ($bulkDeadline === '') {
            return '';
        }
        try {
            return Carbon::parse($bulkDeadline)->format('n/j');
        } catch (\Exception $e) {
            return '';
        }
    }
}
