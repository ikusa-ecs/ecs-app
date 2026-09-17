<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\ActiveBonus;
use App\Support\AssignMtg;
use App\Support\AssignmentRole;
use App\Support\ChatworkMentions;
use App\Support\ChatworkRooms;
use App\Support\DangerCalendar;
use App\Support\DangerDays;
use App\Support\LineGroupText;
use App\Support\OfficeScope;
use App\Support\StaffLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * 設定（S / /settings）。
 *
 * これまで画面はマスタ件数（拠点◯件／コンテンツ◯件／ポジション◯件）を
 * HTML にベタ書きしていた＝DB の実数とズレる「嘘表示」だった。
 * ここでは実データから件数を数えて渡す（表示だけ・保存はまだモック）。
 *
 * ※「拠点」の元データは people.office（事務所）を使う。専用の拠点マスタ表は未整備。
 *   もし「拠点＝実施場所（イベント東 等）」の意味に変えるなら、集計元を差し替える。
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        // 拠点＝事務所（people.office）の種類数。
        $offices = Person::whereNotNull('office')
            ->where('office', '!=', '')
            ->distinct()
            ->pluck('office');

        // ポジション（役割）＝正本 AssignmentRole の全コード。
        $positionLabels = array_values(AssignmentRole::LABELS);

        // どの拠点の設定を編集しているか。既定は自分の拠点（管理者は ?office= で切り替え）。
        $office = OfficeScope::filterSingle($request);

        return view('settings', [
            'masterCounts' => [
                'offices' => [
                    'count' => $offices->count(),
                    'examples' => $offices->implode('／'),
                ],
                'contents' => [
                    'count' => Content::count(),
                ],
                'positions' => [
                    'count' => count($positionLabels),
                    'examples' => implode('／', $positionLabels),
                ],
            ],
            // ⚠ MTG日と「その拠点だけの危険日」は拠点ごとに持つ（2026-08-26 baba要望）。
            //   どの拠点を編集しているかは ?office= で切り替える（画面の「拠点」で選ぶ）。
            'settingsOffice' => $office,
            'offices' => OfficeScope::options(),
            // アサインMTG日の予定表（その拠点ぶん・昇順）。案件登録の「追加案件」自動判定に使う。
            'assignMtgDates' => AssignMtg::dates($office),
            // 今日までで一番新しいMTG日＝現在の基準日（無ければ null）。表示用。
            'assignMtgCurrent' => AssignMtg::current(null, $office),
            // 危険日（手動指定）。全拠点共通と、その拠点だけ、を分けて渡す。
            'dangerDatesAll' => DangerDays::allOfficesDates(),
            'dangerDatesOffice' => DangerDays::officeDates($office),
            // 大型案件の開催日一覧（これから開催・完了/下書き以外）＝危険日にワンクリックで足す候補。
            'bigEventDates' => $this->bigEventDates(),
            // スタッフ画面に出す便利リンク集（Notion・アンケートフォーム等）。
            'staffLinks' => StaffLinks::all(),
            // 入力欄の文字数上限。画面側で入力を止めるために渡す（正本は StaffLinks の定数）。
            'staffLinkLimits' => [
                'title' => StaffLinks::MAX_TITLE,
                'memo' => StaffLinks::MAX_MEMO,
                'url' => StaffLinks::MAX_URL,
            ],
            // LINEの概要文のいちばん下に付ける定型文（2026-09-16 baba要望）。
            // ⚠ 正本＝App\Support\LineGroupText。画面に文面を書かない。
            'lineNotice' => LineGroupText::notice(),
            'lineNoticeMax' => LineGroupText::NOTICE_MAX,
            // チャットワークの知らせでメンションする人の選択肢（社員・名簿の並び）。
            // ⚠ 正本＝App\Support\ChatworkMentions。画面にチャットワークIDを書かない。
            'mentionPeople' => ChatworkMentions::options(),
            'mentionDailyReport' => ChatworkMentions::mentionDailyReport(),
            // 危険日をカレンダーに入れるときの文面（2026-09-16 baba要望）。
            // ⚠ 正本＝App\Support\DangerCalendar。画面に文面や時間を直書きしない。
            'dangerCalTitle' => DangerCalendar::title(),
            'dangerCalBody' => DangerCalendar::bodyText(),
            'dangerCalTitleDefault' => DangerCalendar::TITLE_DEFAULT,
            'dangerCalTitleMax' => DangerCalendar::TITLE_MAX,
            'dangerCalBodyMax' => DangerCalendar::BODY_MAX,
            'dangerCalStart' => DangerCalendar::START_TIME,
            'dangerCalEnd' => DangerCalendar::END_TIME,
            'dangerCalMonths' => DangerCalendar::MONTHS_AHEAD,
            // 繁忙期ボーナスの決まり（2026-09-17 baba要望）。
            // ⚠ 正本＝App\Support\ActiveBonus。画面に階層や単価を直書きしない。
            'abTiers' => ActiveBonus::tiers(),
            'abHours' => ActiveBonus::hours(),
            'abSpotCost' => ActiveBonus::spotCost(),
            'abEnabled' => ActiveBonus::enabled(),
        ]);
    }

    /**
     * 繁忙期ボーナスの決まりを保存する（POST /settings/active-bonus）。
     *
     * ここで決めた階層・単価は、繁忙期ボーナス画面(/active-bonus)とスタッフ画面の
     * 「あと◯回でボーナス」の**両方**に効く（計算は ActiveBonus 1か所だけ）。
     *
     * ⚠ 「実施中」を OFF にすると、左メニューとスタッフ画面から消える。
     *   数字だけ見たいときは /active-bonus を直接開けば見られる（OFFでも計算はする）。
     */
    public function saveActiveBonus(Request $request)
    {
        $data = $request->validate([
            'counts' => ['present', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'rates' => ['present', 'array'],
            'rates.*' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'hours' => ['required', 'integer', 'min:1', 'max:24'],
            'spot_cost' => ['required', 'integer', 'min:0', 'max:1000000'],
            'enabled' => ['nullable'],
        ]);

        // 回数と単価は同じ行どうしを組にする。どちらかが空の行は入れない（書きかけを保存しない）。
        $tiers = [];
        foreach ($data['counts'] as $i => $count) {
            $rate = $data['rates'][$i] ?? null;
            if ($count !== null && $rate !== null) {
                $tiers[] = ['count' => (int) $count, 'rate' => (int) $rate];
            }
        }

        if ($tiers === []) {
            return back()->with('active_bonus_status', '階層を1つも読み取れませんでした。回数と上がる時給の両方を入れてください。');
        }

        ActiveBonus::save($tiers, (int) $data['hours'], (int) $data['spot_cost'], (bool) ($data['enabled'] ?? false));

        return back()->with('active_bonus_status', '繁忙期ボーナスの決まりを保存しました。');
    }

    /**
     * 危険日をカレンダーに入れるときの文面を保存する（POST /settings/danger-calendar）。
     *
     * 実際にカレンダーへ入れるのはGAS。ECSは文面を渡すだけなので、ここを直せば
     * **次にGASが動いたときから**新しい文面になる（すでに入っている予定も書き直される）。
     *
     * ⚠ タイトルは空にできない（名前のない予定を作らないため）。空で送られたら初期値に戻る。
     */
    public function saveDangerCalendar(Request $request)
    {
        DangerCalendar::saveTitle((string) $request->input('title', ''));
        DangerCalendar::saveBody((string) $request->input('body', ''));

        return back()->with('danger_cal_status', '危険日の予定の文面を保存しました。');
    }

    /**
     * これから開催される「大型」案件の一覧（危険日追加の候補）。
     * [ '日付'=>Y-m-d, 'label'=>「7/25（金）」, 'name'=>案件名 ] の配列（開催日昇順）。
     */
    private function bigEventDates(): array
    {
        $today = Carbon::today();
        $weekdays = ['日', '月', '火', '水', '木', '金', '土'];

        return Project::where('scale', '大型')
            ->whereNotNull('start_date')
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get(['project_name', 'client', 'office', 'start_date'])
            ->filter(fn (Project $p) => $p->start_date->gte($today))
            ->map(fn (Project $p) => [
                'date' => $p->start_date->format('Y-m-d'),
                'label' => (int) $p->start_date->format('n') . '/' . (int) $p->start_date->format('j')
                    . '（' . $weekdays[(int) $p->start_date->format('w')] . '）',
                'name' => $p->project_name,
                // 企業名（クライアント）も出す＝同じコンテンツが並ぶとどの案件か分からないため
                // （2026-08-26 baba要望）。未入力の案件もあるので空のこともある。
                'client' => (string) ($p->client ?? ''),
                // どの拠点の案件か＝拠点ごとの危険日を足すときの目印。
                'office' => (string) ($p->office ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * どの拠点の設定を保存するか。
     * ⚠ 保存は fetch のJSON（body）で届くので、?office= を見る OfficeScope::filterSingle では
     *   拾えない。画面から送られた拠点が拠点マスタにあればそれを使い、無ければ自分の拠点。
     * ⚠ 一般社員は自分の拠点に固定される（filterSingle が他拠点を返さない）。
     */
    private function targetOffice(Request $request): string
    {
        $sent = trim((string) $request->input('office', ''));
        if ($sent !== '' && in_array($sent, OfficeScope::options(), true)
            && OfficeScope::canSeeAll()) {
            return $sent;
        }

        return OfficeScope::filterSingle($request);
    }

    /**
     * 危険日（手動指定）を保存する（POST /settings/danger-dates）。
     *
     * scope で保存先が変わる（2026-08-26 baba要望）：
     *   ・all    … 全拠点共通の危険日（どの拠点の画面にも出る）
     *   ・office … その拠点だけの危険日
     * ⚠ 全拠点共通は今までのキーそのままなので、昔からの危険日は「全拠点」として残る。
     */
    public function saveDangerDates(Request $request)
    {
        $data = $request->validate([
            'dates'   => ['present', 'array'],
            'dates.*' => ['date'],
            'scope'   => ['nullable', 'in:all,office'],
            'office'  => ['nullable', 'string'],
        ]);

        $all = ($data['scope'] ?? 'office') === 'all';

        return response()->json([
            'ok'    => true,
            'scope' => $all ? 'all' : 'office',
            'dates' => $all
                ? DangerDays::saveAllOffices($data['dates'])
                : DangerDays::saveOffice($data['dates'], $this->targetOffice($request)),
        ]);
    }

    /**
     * チャットワークの送り先（知らせごとの部屋）を保存する。2026-09-15 baba要望。
     *
     * ⚠ **部屋IDは鍵ではない**ので、.env ではなく設定画面で変えられるようにしている
     *   （送り先は運用で変わるもの。変えるたびにエンジニア依頼にしない）。
     * ⚠ ふつうのフォームで受ける（この画面のほかの設定はAJAXだが、ここは押した瞬間に
     *   保存されて結果が画面に出るほうが分かりやすいため）。
     */
    public function saveChatworkRooms(Request $request)
    {
        $rooms = $request->input('rooms', []);
        $rejected = ChatworkRooms::save(is_array($rooms) ? $rooms : []);

        // メンションする人（2026-09-17 baba要望）。正本＝App\Support\ChatworkMentions。
        // ⚠ チェックを全部外した知らせは、画面から何も送られてこない（チェックボックスの仕様）。
        //   そのままだと「外したのに残る」ので、**画面に出ている知らせは必ず保存し直す**。
        $mentions = $request->input('mentions', []);
        $mentions = is_array($mentions) ? $mentions : [];
        foreach (array_keys(ChatworkRooms::KINDS) as $kind) {
            $ids = $mentions[$kind] ?? [];
            ChatworkMentions::save($kind, is_array($ids) ? $ids : []);
        }

        // 毎朝の「届きました」にもメンションを付けるか（既定＝付けない）。
        ChatworkMentions::saveMentionDailyReport($request->boolean('mention_daily_report'));

        return back()
            ->with('chatwork_rooms_status', 'チャットワークの送り先とメンションを保存しました。')
            ->with('chatwork_rooms_rejected', $rejected);
    }

    /**
     * LINEの概要に付ける定型文を保存する（POST /settings/line-notice）。
     *
     * アサインボード（日別）の「📱 LINE」で出る概要文の、いちばん下に付く文章。
     * 動画チェックのフォルダやレクチャーフォームのURLが変わっても、
     * コードを直さずにこの画面から変えられるようにするためのもの（2026-09-16 baba要望）。
     *
     * ⚠ 空で保存できる（＝定型文を付けない）。空文字と「一度も設定していない」は
     *   LineGroupText::notice() で区別している（未設定のときだけ初期値が出る）。
     */
    public function saveLineNotice(Request $request)
    {
        $saved = LineGroupText::saveNotice((string) $request->input('notice', ''));

        return back()->with(
            'line_notice_status',
            $saved === ''
                ? '定型文を空にしました。概要文には何も付きません。'
                : 'LINEの概要に付ける定型文を保存しました。'
        );
    }

    /**
     * アサインMTG日の予定表を保存する（POST /settings/mtg-dates）。
     * 全員に効く共通設定なので settings テーブル（key='assign_mtg_dates'）にまとめて保存する。
     * 複数の日付を登録でき、案件登録フォームは「今日までで一番新しいMTG日」を基準に
     * 開催日がそれより後の登録を自動で「追加案件」にする。
     */
    public function saveMtgDates(Request $request)
    {
        $data = $request->validate([
            'dates'   => ['present', 'array'],
            'dates.*' => ['date'],
            'office'  => ['nullable', 'string'],
        ]);

        // ⚠ どの拠点のMTG日かを必ず決めて保存する（全国共通に書き戻さない）。
        $office = $this->targetOffice($request);
        $list = AssignMtg::save($data['dates'], $office);

        return response()->json([
            'ok'      => true,
            'office'  => $office,
            'dates'   => $list,
            'current' => AssignMtg::current(null, $office),
        ]);
    }

    /**
     * スタッフ画面の便利リンク集を保存する（POST /settings/staff-links）。
     * 全スタッフに見える共通設定なので settings テーブル（key='staff_links'）にまとめて保存する。
     * URLが変わるたびにコードを直さなくて済むよう、この画面から社員が編集できる。
     */
    public function saveStaffLinks(Request $request)
    {
        // このアプリは api/* 以外を JSON で返さない設定（bootstrap/app.php）なので、
        // $request->validate() だと失敗時に画面ごとリダイレクトされてしまう。
        // ここは画面から fetch で叩くAJAXなので、自分で検証して JSON の 422 を返す。
        $validator = Validator::make($request->all(), [
            'links'         => ['present', 'array', 'max:50'],
            'links.*.title' => ['required', 'string', 'max:' . StaffLinks::MAX_TITLE],
            // http/https だけ許可（javascript: などをスタッフ画面に出さないため）
            'links.*.url'   => ['required', 'string', 'max:' . StaffLinks::MAX_URL, 'url:http,https'],
            'links.*.memo'  => ['nullable', 'string', 'max:' . StaffLinks::MAX_MEMO],
        ], [
            // 既定の英語メッセージ（links.0.memo ...）では何が悪いか分からないので日本語で出す。
            'links.*.title.required' => '「表示する名前」が空の行があります。',
            'links.*.title.max'      => '「表示する名前」は' . StaffLinks::MAX_TITLE . '文字までです。長い行を短くしてください。',
            'links.*.url.required'   => 'URLが空の行があります。',
            'links.*.url.url'        => 'URLは https:// から始まる形で入れてください。',
            'links.*.url.max'        => 'URLは' . StaffLinks::MAX_URL . '文字までです。',
            'links.*.memo.max'       => '「ひとこと説明」は' . StaffLinks::MAX_MEMO . '文字までです。長い行を短くしてください。',
        ]);

        if ($validator->fails()) {
            // 以前は理由に関係なく「名前とURLを入れてください」と返していたため、
            // 「説明が長すぎる」で弾かれても画面に理由が出ず、原因不明の保存失敗に見えていた。
            // 実際に引っかかった項目のメッセージをそのまま返す。
            return response()->json([
                'ok'      => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()->toArray(),
            ], 422);
        }

        return response()->json([
            'ok'    => true,
            'links' => StaffLinks::save($validator->validated()['links']),
        ]);
    }
}
