<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Models\ShiftPreference;
use App\Models\StaffRoleEligibility;
use App\Support\AssignmentRole;
use App\Support\OfficeScope;
use App\Support\OfficeSettings;
use App\Support\ProjectFormats;
use App\Support\StaffLinks;
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
    /**
     * 募集人数が未定（空欄・0）のときにスタッフ画面へ見せる既定の人数。
     * 人数はセールスが案件登録で入れるのが正しいが、未定のまま公開されても
     * 「満員＝エントリーできない」にならないようにするための保険（2026-08-20 baba 指示）。
     */
    private const DEFAULT_NEED = 5;

    public function index()
    {
        $today = Carbon::today();

        // ログイン本人（people名簿の1行）。本人の確定アサインだけを見せるために使う。
        $me = Auth::user();

        // 本人の「確定」アサインだけを引く（仮＝まだ調整中はスタッフに見せない）。
        // ⚠ ここを status!='キャンセル' にすると「仮」も本人の確定アサインとして出てしまう。
        //   画面の説明文（調整中のものは出ません）と食い違うので、必ず '確定' で絞る。
        // 1行＝案件×日なので、同じ案件で複数日ある人は日ごとに1件ずつ出る（2日目だけの担当も正しい日で出る）。
        $mine = $me
            ? Assignment::where('staff_id', $me->id)
                ->where('status', '確定')
                ->orderBy('date')
                ->get()
            : collect();

        // 公開ON かつ「下書き・完了ではない」案件だけを取り出す（本人がアサインされた案件に限る）。
        // 公開していない案件はスタッフに一切見せない＝公開ボードの「公開する」が唯一の入口。
        $projects = $mine->isEmpty()
            ? collect()
            : Project::whereIn('id', $mine->pluck('project_id')->unique()->all())
                ->where('staff_published', true)
                ->notCancelled()   // キャンセルになった案件は本人にも見せない（2026-08-26）
                ->whereNotIn('status', ['下書き', '完了'])
                ->get()
                ->keyBy('id');

        $published = $mine
            ->map(function (Assignment $a) use ($today, $projects) {
                $p = $projects->get($a->project_id);
                if (! $p) {
                    return null;   // 未公開／下書き／完了の案件は出さない
                }

                // 手動アーカイブされた案件は出さない（案件一覧と同じ扱い）。
                if ($p->is_archived === true) {
                    return null;
                }

                // 表示する日＝そのアサインの日（無ければ案件の開催日）。
                $date = $a->date ?: $p->start_date;
                if (! $date) {
                    return null;
                }

                // off ＝ 今日から何日後か（マイナス＝過去）。画面が日付計算に使う。
                $off = intdiv($date->copy()->startOfDay()->timestamp - $today->timestamp, 86400);

                return [
                    'id' => $p->id,
                    'content' => $p->project_name,
                    'client' => $p->client ?? '',
                    'place' => $p->location ?? '',
                    'meetPlace' => $p->assembly_type ?? '',
                    // スタッフ向けの集合・解散時間。担当が公開ボードで直していれば優先、無ければ社員の時間。
                    'meet' => $p->staff_meet_time ?? $p->start_time ?? '—',
                    'leave' => $p->staff_leave_time ?? $p->end_time ?? '—',
                    'off' => $off,
                    // 本人の担当ポジション（表示名）。役割コード→表示名は必ず AssignmentRole::label() を使う
                    // （日本語直書き禁止＝表記ゆれ防止）。role2 は兼任。
                    'mine' => true,
                    'myRole' => AssignmentRole::label($a->role),
                    'myRole2' => AssignmentRole::label($a->role2),
                    // 当日の詳細（スタッフ画面の詳細パネルで見せる）。
                    'enter' => $p->event_enter_time ?? '',
                    'evStart' => $p->event_start_time ?? '',
                    'evEnd' => $p->event_end_time ?? '',
                    'evTbd' => (bool) $p->event_time_tbd,   // 本番時間未定（案件登録のチェック）
                    'lodging' => $p->lodging ?? '',
                    'outdoor' => (bool) $p->is_outdoor,
                    // 当日必要な情報（案件登録・公開ボードのどちらからでも入れられる）
                    'meetDetail' => (string) ($p->assembly_detail ?? ''),
                    'belongings' => (string) ($p->staff_belongings ?? ''),
                    'dresscode' => (string) ($p->staff_dresscode ?? ''),
                    'staffNotes' => (string) ($p->staff_notes ?? ''),
                    // 担当がこの人だけに向けて書いた一言（assignments.remark）
                    'myNote' => trim((string) ($a->remark ?? '')),
                ];
            })
            ->filter()
            // 過去の日は出さない（募集タブと同じ扱い＝当日は出す）。
            ->filter(fn ($c) => $c['off'] >= 0)
            ->sortBy('off')
            ->values();

        // 稼働希望カレンダーの対象月。既定＝今日基準の当月（?period= があればそれを優先）。
        $prefPeriod = $this->prefPeriod();

        return view('staff_portal', [
            'published' => $published,
            'recruitJobs' => $this->recruitJobs($today, $me),
            // お知らせ文＝本人の拠点のもの（2026-08-25 baba要望：拠点ごとに出し分ける）。
            'notice' => OfficeSettings::get(OfficeSettings::NOTICE, OfficeScope::filter(request())),
            // 体験用（見本）アカウントか。true のときは応募・希望が保存されないので、
            // 画面の上に注意を出す（2026-08-21 baba：保存されないのに応募できたように見えた）。
            'mockOnly' => (! $me || TestAccounts::isMockOnly($me)),
            'myProfile' => $this->myProfile($me),           // 設定タブの初期表示（本人のDB値）
            'prefPeriod' => $prefPeriod,                     // 稼働希望カレンダーの対象月（当月）
            'prefMeta' => $this->prefMeta($prefPeriod),      // その月の見出し・締切・日数・1日の曜日
            'myPrefs' => $this->myPrefs($me),               // 本人の希望（カレンダー初期表示）
            'myPrefMemo' => $this->myPrefMemo($me, $prefPeriod), // 希望のコメント（初期表示）
            'staffLinks' => StaffLinks::all(),               // 便利リンク集（共通設定で社員が編集）
        ]);
    }

    /**
     * 稼働希望カレンダーの対象月（YYYY-MM）。
     * 既定＝今日基準の当月（now()）。URL に ?period=YYYY-MM が付いていればそれを優先。
     * ※以前は '2026-07' 直書きだったが、月が変わっても正しい当月を見せるため今日基準にした。
     */
    private function prefPeriod(): string
    {
        $param = (string) request('period', '');
        if (preg_match('/^\d{4}-\d{1,2}$/', $param)) {
            return $param;
        }

        return now()->format('Y-m');
    }

    /**
     * 稼働希望カレンダーの「その月の情報」。画面の見出し・マスの並びに使う。
     *
     * これまで画面に「2026年7月」「1日は火曜（＝先頭の空マス2つ）」「31日ぶん」が
     * 直書きされていたため、月が変わると表示と保存対象月がズレていた。ここで月から計算して渡す。
     *
     * ⚠ 以前は「締切＝対象月の前月25日」も計算して画面に出していたが、やめた（2026-08-25 baba）。
     *   実際の運用の締切と合っておらず、過ぎた日付が出て混乱のもとになるため。
     *   締切の連絡はLINEで行う（ECS＝見せる・記録する／LINE＝連絡する、の分担）。
     *
     * @return array{year:int,month:int,days:int,firstDow:int,label:string}
     */
    /** 稼働希望カレンダーで先に進める月数（当月＋この数）。半年先まで出せる（2026-08-21 baba）。 */
    private const PREF_MONTHS_AHEAD = 6;

    private function prefMeta(string $period): array
    {
        [$y, $m] = array_map('intval', array_pad(explode('-', $period), 2, 1));
        $first = Carbon::create($y, $m, 1)->startOfDay();

        // 月を切り替えられる範囲＝当月から半年先まで（2026-08-21 baba）。
        // 大型案件は早くから日程が決まるので、先の月の希望も出しておけるようにする。
        // 下限は当月（過ぎた月の希望を出す意味がないため）。
        $min = Carbon::now()->startOfMonth();
        $max = $min->copy()->addMonthsNoOverflow(self::PREF_MONTHS_AHEAD);
        $prev = $first->copy()->subMonthNoOverflow();
        $next = $first->copy()->addMonthNoOverflow();

        return [
            'year' => $y,
            'month' => $m,
            'days' => $first->daysInMonth,
            'firstDow' => (int) $first->dayOfWeek,   // 0=日曜
            'label' => $y.'年'.$m.'月',
            // いま表示している月（YYYY-MM に揃えたもの）。プルダウンの選択中の判定に使う。
            'value' => $first->format('Y-m'),
            // 月の切り替え（2026-08-21 baba要望）。範囲外は null＝ボタンを出さない。
            'prev' => $prev->gte($min) ? $prev->format('Y-m') : null,
            'next' => $next->lte($max) ? $next->format('Y-m') : null,
            'prevLabel' => $prev->format('n月'),
            'nextLabel' => $next->format('n月'),
            // 月を直接選べるプルダウン用（当月〜半年先）。押す回数を減らすため。
            'months' => $this->prefMonthOptions($min, $max),
            'isPast' => $first->lt($min),   // 過ぎた月＝入力してもらう月ではない
        ];
    }

    /**
     * 稼働希望カレンダーの月プルダウンの選択肢（当月〜半年先）。
     *
     * @return array<int, array{value:string,label:string}>
     */
    private function prefMonthOptions(Carbon $min, Carbon $max): array
    {
        $out = [];
        for ($d = $min->copy(); $d->lte($max); $d->addMonthNoOverflow()) {
            $out[] = ['value' => $d->format('Y-m'), 'label' => $d->format('Y年n月')];
        }

        return $out;
    }

    /** DBの availability → カレンダーの状態語（ok/ng/maybe）。「希望」も画面では〇(ok)扱い。 */
    private const PREF_TO_VIEW = ['稼働可' => 'ok', '希望' => 'ok', 'NG' => 'ng', '未定' => 'maybe'];

    /**
     * 設定タブ（プロフィール／できるポジション・スキル）の初期表示に使う、本人のDB値。
     * テスト用アカウント（DBに実体が無い）や未ログインのときは null＝画面は空欄で開く。
     */
    private function myProfile($me): ?array
    {
        if (! $me || TestAccounts::isMockOnly($me)) {
            return null;
        }

        $elig = $me->roleEligibilities->pluck('position')->all();

        return [
            'height' => $me->height,
            'shoe_size' => $me->shoe_size,
            'shirt_size' => $me->shirt_size,
            'prefecture' => $me->prefecture,
            'nearest_station' => $me->nearest_station,
            'appeal' => $me->appeal,
            'liked_contents' => $me->liked_contents,
            'disliked_contents' => $me->disliked_contents,
            'strong_positions' => $me->strong_positions,
            'weak_positions' => $me->weak_positions,
            'mcPass' => (bool) $me->mc_audition_passed,
            'kigurumi' => (bool) $me->can_kigurumi,
            'stay' => (bool) $me->can_stay_over,
            'drive' => $me->driving_level,
            'english' => $me->english_level,
            // OP・軍師(SP) は「できる役割」= staff_role_eligibility。トグルの初期ON/OFFに使う。
            'op' => in_array(AssignmentRole::OP, $elig, true),
            'gunshi' => in_array(AssignmentRole::SP, $elig, true),
        ];
    }

    /**
     * 設定タブ「プロフィール（自分の情報）」をDB(people)へ保存（AJAX・本人分のみ）。
     * これまで localStorage 保存だったものを本物化。テスト用アカウントは保存しない（/profile と同じ方針）。
     */
    public function saveProfile(Request $request)
    {
        $user = Auth::user();
        if (! $user || TestAccounts::isMockOnly($user)) {
            return response()->json(['ok' => false, 'message' => 'テスト用アカウントは保存できません（見本のため）。']);
        }

        $data = $request->validate([
            'height' => ['nullable', 'string', 'max:20'],
            'shoe_size' => ['nullable', 'string', 'max:20'],
            'shirt_size' => ['nullable', 'string', 'max:20'],
            'prefecture' => ['nullable', 'string', 'max:20'],
            'nearest_station' => ['nullable', 'string', 'max:100'],
            'appeal' => ['nullable', 'string', 'max:1000'],
            'liked_contents' => ['nullable', 'string', 'max:1000'],
            'disliked_contents' => ['nullable', 'string', 'max:1000'],
            'strong_positions' => ['nullable', 'string', 'max:1000'],
            'weak_positions' => ['nullable', 'string', 'max:1000'],
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
        if (! $user || TestAccounts::isMockOnly($user)) {
            return response()->json(['ok' => false, 'message' => 'テスト用アカウントは保存できません（見本のため）。']);
        }

        $user->mc_audition_passed = $request->boolean('mc');
        $user->can_kigurumi = $request->boolean('kigurumi');
        $user->can_stay_over = $request->boolean('stay');
        $drive = trim((string) $request->input('drive', ''));
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
        if (! $me || TestAccounts::isMockOnly($me)) {
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
    private function myPrefMemo($me, string $period): string
    {
        if (! $me || TestAccounts::isMockOnly($me)) {
            return '';
        }
        $row = ShiftPreference::where('staff_id', $me->id)
            ->where('period', $period)
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
        if (! $user || TestAccounts::isMockOnly($user)) {
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
     * 案件へのエントリー（応募＋一言コメント）を DB(applications) へ本物保存（AJAX・本人分のみ）。
     * これまで画面はモックで状態を切り替えるだけだったものを本物化。
     *  - action=apply（既定）… 応募（同じ人×同じ案件は上書き＝updateOrCreate）。
     *  - action=cancel        … 応募の取り消し（その行を削除）。
     * テスト用アカウント・未ログインは保存しない（見本のため）＝ saveProfile/saveAvailability と同じ方針。
     */
    public function saveEntry(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'intent' => ['nullable', 'in:希望,可'],
            'note' => ['nullable', 'string', 'max:1000'],
            'action' => ['nullable', 'in:apply,cancel'],
        ]);

        $action = $data['action'] ?? 'apply';
        $applied = ($action !== 'cancel');   // apply→応募済み・cancel→未応募

        $user = Auth::user();

        // テスト用アカウント・未ログインは実DBに本人が居ないので保存しない（見本）。
        // 画面は成功として扱えるよう ok:true を返しつつ saved:false で「保存はしていない」と伝える。
        if (! $user || TestAccounts::isMockOnly($user)) {
            return response()->json([
                'ok' => true,
                'saved' => false,
                'applied' => $applied,
                'message' => 'テスト用アカウントのため保存されません（見本）。',
            ]);
        }

        if ($action === 'cancel') {
            Application::where('staff_id', $user->id)
                ->where('project_id', $data['project_id'])
                ->delete();

            return response()->json(['ok' => true, 'saved' => true, 'applied' => false]);
        }

        // 応募（同じ人×同じ案件は1行だけ＝上書き）。
        Application::updateOrCreate(
            ['staff_id' => $user->id, 'project_id' => $data['project_id']],
            [
                'intent' => $data['intent'] ?? '希望',
                'note' => $data['note'] ?? null,
                'applied_at' => now(),
            ]
        );

        return response()->json(['ok' => true, 'saved' => true, 'applied' => true]);
    }

    /**
     * 募集中タブに出す案件リスト（projects から）。
     * 「募集する・完了/下書きでない」案件を、画面の jobs 変換がそのまま読める項目名で返す。
     * 本人がログイン中なら「自分が応募済みか（applied）＋intent/コメント」も案件ごとに載せる。
     */
    private function recruitJobs(Carbon $today, $me)
    {
        // 本人の応募（案件ID → 行）。テスト/未ログインは空＝応募状態なし。
        // ※ 拠点で絞るときに「すでに応募した案件」を落とさないよう、先に引いておく。
        $myApps = ($me && ! TestAccounts::isMockOnly($me))
            ? Application::where('staff_id', $me->id)->get()->keyBy('project_id')
            : collect();

        // 募集は自分の拠点の案件だけ見せる（全拠点運用・2026-08-05 baba確定＝公開ボードと合わせる）。
        // スタッフは権限 staff なので OfficeScope は常に「自分の拠点」を返す（切替は無い）。
        // ⚠ すでに応募した案件は、他拠点でも残す（応募したのに一覧から消えると取り消せなくなる）。
        $office = OfficeScope::filter(request());
        $appliedIds = $myApps->keys()->all();

        // ⚠ 公開ボードで「公開する」を押した案件だけを募集タブに出す（staff_published）。
        //   ここを is_recruiting だけで判定すると、セールスが登録した瞬間（＝まだ調整中）に
        //   クライアント名・会場までスタッフ全員に見えてしまう。公開の入口は公開ボードの1つだけにする。
        //   ※すでに応募した案件は、非公開に戻されても残す（一覧から消えると取り消せなくなるため）。
        $projects = Project::where('is_recruiting', true)
            ->notCancelled()   // キャンセルになった案件は募集もしない（2026-08-26）
            ->whereNotIn('status', ['完了', '下書き'])
            ->where(function ($q) use ($appliedIds) {
                $q->where('staff_published', true);
                if ($appliedIds) {
                    $q->orWhereIn('id', $appliedIds);
                }
            })
            ->when($office, fn ($q) => $q->where(function ($qq) use ($office, $appliedIds) {
                OfficeScope::applyToProjects($qq, $office);
                if ($appliedIds) {
                    $qq->orWhereIn('id', $appliedIds);
                }
            }))
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

        // 通常案件の締切＝**その拠点の**「一斉締切日」（settings）。追加案件は下の deadlineLabel で個別計算。
        // ⚠ 以前は全国共通だったため、東京の締切が東北のスタッフにも出ていた（2026-08-25 baba）。
        $bulkDeadline = OfficeSettings::get(OfficeSettings::DEADLINE, $office);

        return $projects->map(function (Project $p) use ($today, $filledByProject, $contentNames, $bulkDeadline, $myApps) {
            $off = $p->start_date
                ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp, 86400)
                : 0;

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? $p->project_name) : $p->project_name;

            // 実施形態のバッジ。⚠ 振り分けは正本（ProjectFormats::badgeCode）に任せる。
            //   ここで自前に「オンライン／ロング／リアル」の3つだけに分けていたため、
            //   **ARENA場所貸し・体験会・未入力は空文字**になっていた。画面はその空文字で
            //   対応表を引くので値が見つからず、そこで募集一覧の描画が止まっていた
            //   （お知らせだけ出て一覧が空。2026-08-28 baba報告）。
            //   badgeCode は必ず何かを返すので、実施形態が増えても止まらない。
            $format = trim((string) ($p->format ?? ''));
            $fmt = ProjectFormats::badgeCode($format);

            // 画面の jobs 変換（staff_portal.blade）がそのまま読める項目名で返す。
            return [
                'id' => $p->id,
                'content' => $content,
                'client' => $p->client ?? '',
                'place' => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'area' => $p->operation_place ?? '',
                'fmt' => $fmt,
                // 実施形態の名前そのもの。バッジにこれを出す＝新しい実施形態が増えても
                // 画面側に書き足さなくてよい（正本＝ProjectFormats::ALL）。
                'fmtText' => $format,
                'scale' => $p->scale ?? '',
                'repeat' => (bool) $p->is_repeat,
                'lodging' => $p->lodging ?? '無',
                'dayType' => $p->date_type ?? '本番',
                'parentId' => $p->parent_project_id,
                'off' => $off,
                // 募集人数は本来セールスが案件登録で入れる項目。まだ未定（空欄・0）のときは
                // 画面が「満員」に見えてエントリーできなくなるので、既定 5名 として見せる（2026-08-20 baba）。
                'need' => ((int) ($p->required_count ?? 0)) > 0 ? (int) $p->required_count : self::DEFAULT_NEED,
                'filled' => $filledByProject->get($p->id, 0),
                'meet' => $p->start_time ?? '—',
                'leave' => $p->end_time ?? '—',
                'enter' => $p->event_enter_time ?? '—',
                'evStart' => $p->event_start_time ?? '—',
                'evEnd' => $p->event_end_time ?? '—',
                'evTbd' => (bool) $p->event_time_tbd,   // 本番時間未定（案件登録のチェック）
                // スタッフに伝えること。応募するかの判断に要るので、確定後だけでなく
                // 募集中のカードにも「備考」として出す（2026-08-21 baba）。
                'staffNotes' => (string) ($p->staff_notes ?? ''),
                'category' => $p->category ?? '通常案件',
                'deadline' => $this->deadlineLabel($p, $bulkDeadline),
                'recruit' => true,
                'archived' => $off < 0,   // 過去のイベントは募集タブに出さない
                'draft' => false,
                // 本人がこの案件に応募済みか＋その内容（画面で応募状態・コメントを復元するために渡す）。
                'applied' => $myApps->has($p->id),
                'myIntent' => optional($myApps->get($p->id))->intent ?? '希望',
                'myNote' => optional($myApps->get($p->id))->note ?? '',
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
            if (! $base) {
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
