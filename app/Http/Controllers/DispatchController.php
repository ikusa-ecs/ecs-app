<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Models\ProjectDispatch;
use App\Support\DispatchStatus;
use App\Support\OfficeScope;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * 派遣依頼（2026-09-03 baba要望「派遣で書いた案件が一覧で出るシートを作りたい」）。
 *
 * ⚠ それまで日別ボードの「＋派遣」は**画面の中だけ**の動きで、DBに何も残っていなかった。
 *   押しても読み込み直すと消え、「どの案件に、どこへ、何名頼んだか」がどこにも無かった。
 *   ＝一覧を作るには、まず保存できるようにする必要があった。
 *
 * この画面（/dispatch-list）＝頼んだ派遣を開催日順に並べたシート。
 * 入れる場所は今までどおり日別ボードの「＋派遣」（POST /dispatches）。
 */
class DispatchController extends Controller
{
    /** 一覧に出す既定の期間（今日から何日先まで）。 */
    private const DEFAULT_DAYS = 92;

    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);

        // 期間。既定＝今日〜3か月先。past=1 のときは過去も全部出す（振り返り用）。
        $withPast = $request->boolean('past');
        $from = $withPast ? null : Carbon::today()->format('Y-m-d');
        $to = $withPast ? null : Carbon::today()->addDays(self::DEFAULT_DAYS)->format('Y-m-d');

        $status = (string) $request->query('status', '');
        if (! in_array($status, DispatchStatus::ALL, true)) {
            $status = '';
        }

        // 拠点で絞るのは案件（＝派遣は案件にぶら下がるもの）。
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->get()
            ->keyBy('id');

        $rows = collect();
        if ($projects->isNotEmpty()) {
            $contentNames = Content::pluck('content_name', 'id');

            // その案件のアサイン人数（埋まり具合を横に出す＝あと何人足りないかが分かる）。
            $filled = Assignment::whereIn('project_id', $projects->keys())
                ->where('status', '!=', 'キャンセル')
                ->get(['project_id', 'staff_id'])
                ->groupBy('project_id')
                ->map(fn ($g) => $g->pluck('staff_id')->unique()->count());

            $rows = ProjectDispatch::whereIn('project_id', $projects->keys())
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderBy('id')
                ->get()
                ->map(function (ProjectDispatch $d) use ($projects, $contentNames, $filled) {
                    $p = $projects->get($d->project_id);
                    $day = $p->start_date?->format('Y-m-d');

                    // コンテンツ名（案件名が「（名称未定）」のことがあるので併記する）。
                    $cids = is_array($p->content_ids ?? null) ? $p->content_ids : [];
                    $contents = collect($cids)->map(fn ($id) => $contentNames[$id] ?? null)->filter()->implode('・');

                    return [
                        'id' => $d->id,
                        'day' => $day,
                        'dayLabel' => $p->start_date ? $p->start_date->format('n/j') : '日付未定',
                        'dow' => $p->start_date ? ['日', '月', '火', '水', '木', '金', '土'][(int) $p->start_date->dayOfWeek] : '',
                        'projectId' => $d->project_id,
                        'projectName' => $p->project_name ?: '（名称未定）',
                        'contents' => $contents,
                        'client' => $p->client ?? '',
                        'place' => $this->cityOf($p->location),
                        'location' => (string) ($p->location ?? ''),
                        'assembly' => (string) ($p->assembly_type ?? ''),
                        'need' => (int) ($p->required_count ?? 0),
                        'filled' => (int) ($filled[$d->project_id] ?? 0),
                        'office' => $p->office ?? '',
                        'agency' => $d->agency,
                        'count' => $d->count,
                        'role' => (string) ($d->role ?? ''),
                        'status' => $d->status,
                        'statusCls' => DispatchStatus::cls($d->status),
                        'requestedOn' => (string) ($d->requested_on ?? ''),
                        'note' => (string) ($d->note ?? ''),
                    ];
                })
                // 期間で絞る。⚠ 開催日が未定の案件は落とさない（頼んだ記録が消えると気づけない）。
                ->filter(function (array $r) use ($from, $to) {
                    if ($r['day'] === null) {
                        return true;
                    }
                    if ($from && $r['day'] < $from) {
                        return false;
                    }

                    return ! ($to && $r['day'] > $to);
                })
                ->sortBy([
                    fn ($a, $b) => ($a['day'] ?? '9999-99-99') <=> ($b['day'] ?? '9999-99-99'),
                    fn ($a, $b) => $a['agency'] <=> $b['agency'],
                ])
                ->values();
        }

        // ⚠ 数え上げとURLは画面（Blade）で作らない。
        //   @php ブロックや href の中のインライン @if は、展開されずに文字がそのまま出ることがあり、
        //   その画面のJavaScriptが丸ごと死ぬ（この画面でも一度踏んだ）。
        $live = $rows->where('status', '!=', DispatchStatus::CANCELLED);
        $q = $status !== '' ? '?status='.urlencode($status) : '';

        return view('dispatch_list', [
            'sumRows' => $live->count(),
            'sumPeople' => $live->sum('count'),
            'sumProjects' => $live->pluck('projectId')->unique()->count(),
            'sumAsked' => $rows->where('status', DispatchStatus::ASKED)->count(),
            'urlFuture' => '/dispatch-list'.$q,
            'urlPast' => '/dispatch-list?past=1'.($status !== '' ? '&status='.urlencode($status) : ''),
            'rows' => $rows,
            'officeScope' => $office,
            'withPast' => $withPast,
            'statusFilter' => $status,
            'statuses' => DispatchStatus::ALL,
            'days' => self::DEFAULT_DAYS,
            // 直せるか＝社員以上（スタッフはこの画面を使わない）。
            // ⚠ 案件ごとの拠点チェックは保存の入口（store/update/destroy）で必ず通す。
            'canEdit' => (bool) Auth::user() && (Auth::user()->role ?? '') !== 'staff',
        ]);
    }

    /** 派遣依頼を1件足す（日別ボードの「＋派遣」・派遣一覧の追加欄から）。 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', 'exists:projects,id'],
            'agency' => ['required', 'string', 'max:100'],
            'count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'role' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', DispatchStatus::inRule()],
            'requested_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'project_id' => '案件', 'agency' => '派遣先', 'count' => '人数',
            'role' => '役割', 'status' => '状態', 'requested_on' => '依頼日', 'note' => '備考',
        ]);

        $project = Project::find($data['project_id']);
        // ⚠ 拠点チェックは保存の入口で必ず通す（他拠点の案件をURL直打ちで書き換えられないように）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }

        $d = ProjectDispatch::create([
            'project_id' => $data['project_id'],
            'agency' => trim($data['agency']),
            'count' => $data['count'] ?? 1,
            'role' => $data['role'] ?? null,
            'status' => $data['status'] ?? DispatchStatus::ASKED,
            'requested_on' => $data['requested_on'] ?? Carbon::today()->format('Y-m-d'),
            'note' => $data['note'] ?? null,
            'created_by' => optional(Auth::user())->id,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $d->id, 'message' => '派遣依頼を保存しました。']);
        }

        return back()->with('status', '派遣依頼を保存しました。');
    }

    /** 派遣依頼を直す（人数・役割・状態・備考）。 */
    public function update(Request $request, int $id)
    {
        $d = ProjectDispatch::find($id);
        if (! $d) {
            return $this->missing($request);
        }
        if ($deny = ProjectAccess::denyJson(Project::find($d->project_id))) {
            return $deny;
        }

        $data = $request->validate([
            'agency' => ['sometimes', 'required', 'string', 'max:100'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'role' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', DispatchStatus::inRule()],
            'requested_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [], [
            'agency' => '派遣先', 'count' => '人数', 'role' => '役割',
            'status' => '状態', 'requested_on' => '依頼日', 'note' => '備考',
        ]);

        // ⚠ 送られてきた欄だけ直す。出していない欄まで空で上書きすると、
        //   別の画面から入れた内容が消える（他の画面と同じ決まり）。
        $d->fill($data)->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => '直しました。']);
        }

        return back()->with('status', '直しました。');
    }

    /** 派遣依頼を消す（打ち間違いのとき用）。頼んだ事実を残したいときは「キャンセル」を使う。 */
    public function destroy(Request $request, int $id)
    {
        $d = ProjectDispatch::find($id);
        if (! $d) {
            return $this->missing($request);
        }
        if ($deny = ProjectAccess::denyJson(Project::find($d->project_id))) {
            return $deny;
        }

        $d->delete();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => '消しました。']);
        }

        return back()->with('status', '消しました。');
    }

    private function missing(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => 'この派遣依頼は見つかりません（すでに消されています）。'], 404);
        }

        return back()->withErrors(['dispatch' => 'この派遣依頼は見つかりません（すでに消されています）。']);
    }

    /**
     * 住所から「都道府県＋市区町村」だけを取り出す（一覧に会場を短く出すため）。
     * ⚠ 取れないときは元の住所の先頭を返す。無理に推測しない。
     */
    private function cityOf(?string $location): string
    {
        $s = trim((string) $location);
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(.{2,3}?[都道府県])((?:.+?[市区町村])?)/u', $s, $m)) {
            return $m[1].$m[2];
        }
        if (preg_match('/^(.+?[市区町村])/u', $s, $m)) {
            return $m[1];
        }

        return mb_substr($s, 0, 12);
    }
}
