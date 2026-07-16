<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * アサイン画面（案件詳細）/assign-detail。
 *
 * 【2026-07-17 入口を本物へ寄せた（baba 承認）】
 * 保存できる本物のアサインは /project-assign（手動アサイン）に一本化した。この画面は
 * その「入口」として振る舞う：
 *   ・?case=<案件ID> 付き（日別ボード・公開ボードの「詳細→」等）→ /project-assign へ自動転送。
 *   ・?case なし（サイドバーから）→ 「アサインする案件を選ぶ」一覧を表示（各行から本物画面へ）。
 * これで見本（保存されない編集）と本物が混在して迷う状態を解消する。
 * ※従来の見本ロジック（提案チーム・代替候補・暫定スコア）は撤去した。
 */
class AssignDetailController extends Controller
{
    public function show(Request $request)
    {
        $id = (string) $request->query('case', '');

        // ① 案件が指定されていて実在するなら、本物のアサイン画面へそのまま転送。
        if ($id !== '' && Project::whereKey($id)->exists()) {
            return redirect('/project-assign?project=' . urlencode($id));
        }

        // ② 案件指定なし（または見つからない）＝「アサインする案件を選ぶ」一覧を表示。
        $today = Carbon::today();
        $projects = Project::with('director:id,name')
            ->whereNotNull('start_date')
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && $p->start_date->gte($today))
            ->values();

        // アサイン済み人数（キャンセル以外）を案件ごとに集計＝一覧で進捗が見えるように。
        $assignedCount = Assignment::whereIn('project_id', $projects->pluck('id'))
            ->where('status', '!=', 'キャンセル')
            ->select('project_id', DB::raw('count(distinct staff_id) as c'))
            ->groupBy('project_id')
            ->pluck('c', 'project_id');

        $cases = $projects->map(function (Project $p) use ($today, $assignedCount) {
            $off = intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400);

            return [
                'id' => $p->id,
                'name' => $p->project_name ?: '（名称未定）',
                'client' => $p->client ?? '',
                'date' => $p->start_date->format('Y-m-d'),
                'dow' => ['日', '月', '火', '水', '木', '金', '土'][(int) $p->start_date->dayOfWeek],
                'off' => $off,
                'cat' => $p->site_category ?: '通常',
                'need' => (int) ($p->required_count ?? 0),
                'done' => (int) ($assignedCount[$p->id] ?? 0),
                'dir' => optional($p->director)->name ?? '未定',
                'place' => $p->location ?? '',
            ];
        })->all();

        return view('assign_pick', ['cases' => $cases]);
    }

    /**
     * ▼▼▼ 以下は旧・見本ロジック（現在は未使用）。参考のため残置。 ▼▼▼
     * 役割コード → この画面の表示ラベル。
     */
    private const CODE_TO_LABEL = [
        'D' => 'D（ディレクター）', 'SD' => 'D（ディレクター）', 'MC' => 'MC（司会進行）',
        'OP' => 'OP（音響）', 'FC' => 'FC（巡回ファシリ）', 'CK' => 'CK（チェッカー）',
        'GUN' => '軍師・サポーター', 'SP' => '軍師・サポーター', 'UKE' => '受付', 'RP' => '受付',
        'ET' => 'その他',
    ];

    /** 役割コード → 「できる役割」バッジのキー（CAN と対応：D/OP/MC/FC/CK/軍師/受付）。 */
    private const CODE_TO_CANKEY = [
        'D' => 'D', 'SD' => 'D', 'MC' => 'MC', 'OP' => 'OP', 'FC' => 'FC', 'CK' => 'CK',
        'GUN' => '軍師', 'SP' => '軍師', 'UKE' => '受付', 'RP' => '受付',
    ];

    /** @deprecated 旧・見本の案件詳細表示（現在はルートから呼ばれない）。 */
    private function legacyShow(Request $request)
    {
        $id = (string) $request->query('case', '');
        $project = Project::with('director:id,name')->find($id);

        // 案件が見つからないときは detail=null で渡す＝Blade は従来の見本表示にフォールバック。
        if (! $project) {
            return view('assign_detail', ['detail' => null]);
        }

        $today = Carbon::today();
        $off = $project->start_date
            ? intdiv($project->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
            : 0;

        // コンテンツ名（先頭）。無ければ案件名で代用。
        $firstContentId = is_array($project->content_ids) ? ($project->content_ids[0] ?? null) : null;
        $contentName = $firstContentId
            ? (Content::whereKey($firstContentId)->value('content_name') ?? $project->project_name)
            : $project->project_name;

        // 実データ：この案件のアサイン（キャンセル以外）と応募。
        $assigns = Assignment::where('project_id', $project->id)
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'role', 'status']);
        $apps = Application::where('project_id', $project->id)->get(['staff_id']);

        $assignedIds = $assigns->pluck('staff_id')->unique();
        $applicantIds = $apps->pluck('staff_id')->unique();

        // 関係する人（名前・区分・できる役割）。
        $people = Person::whereIn('id', $assignedIds->merge($applicantIds)->unique()->all())
            ->with('roleEligibilities:staff_id,position')
            ->get()
            ->keyBy('id');

        // 稼働率は稼働状況と同じ単一ソースから（staff_id => rate%）。
        $rateByStaff = app(StaffStatusController::class)->buildStatus()
            ->pluck('rate', 'id');

        // 提案チーム＝実際のアサイン（in:true）。
        $roster = [];
        foreach ($assigns as $a) {
            $roster[] = $this->rosterRow($people->get($a->staff_id), $a->staff_id, $a->role, true, $rateByStaff, '現在この案件にアサイン済み');
        }
        // 代替候補＝応募者のうち、まだアサインされていない人（in:false）。
        foreach ($applicantIds->diff($assignedIds) as $sid) {
            $roster[] = $this->rosterRow($people->get($sid), $sid, null, false, $rateByStaff, 'この案件に応募（エントリー）');
        }

        return view('assign_detail', [
            'detail' => [
                'found' => true,
                'case' => [
                    'id' => $project->id,
                    'name' => $project->project_name ?: '（名称未定）',
                    'client' => $project->client ?? '',
                    'cat' => $project->site_category ?: '通常',
                    'need' => (int) ($project->required_count ?? 0),
                    'off' => $off,
                    'dir' => optional($project->director)->name ?? '未定',
                    'call' => $project->start_time ?? '—',
                    'place' => $project->location ?? '—',
                    'content' => $contentName,
                ],
                'roster' => $roster,
            ],
        ]);
    }

    /** 1人ぶんの行（提案チーム／代替候補で共通）を、画面が使う形に整える。 */
    private function rosterRow(?Person $person, string $sid, ?string $roleCode, bool $in, $rateByStaff, string $reasonText): array
    {
        $exp = (int) (optional($person)->experience_count ?? 0);
        $rate = $rateByStaff[$sid] ?? null;

        // できる役割（バッジ・可否判定用）。DBのコードを画面のキー（軍師/受付 等）に変換。
        $can = [];
        if ($person) {
            foreach ($person->roleEligibilities->pluck('position') as $code) {
                if (isset(self::CODE_TO_CANKEY[$code])) {
                    $can[] = self::CODE_TO_CANKEY[$code];
                }
            }
            $can = array_values(array_unique($can));
        }

        // 現在の担当ラベル。役割未設定は FC（既定・Dにしない）。
        $posLabel = ($roleCode && isset(self::CODE_TO_LABEL[$roleCode]))
            ? self::CODE_TO_LABEL[$roleCode]
            : 'FC（巡回ファシリ）';

        return [
            'id' => $sid,
            'name' => optional($person)->name ?? $sid,
            'lv' => match (optional($person)->skill_level) {
                'ベテラン' => 'vet',
                '新人' => 'new',
                default => 'mid',
            },
            'pos' => $posLabel,
            'can' => $can,
            // 稼働率は本物（希望0件などで算出不可のときは 0 表示）。
            'rate' => $rate ?? 0,
            // 希望充足の細かい数値は未接続＝「—」（嘘の数字を出さない）。
            'fill' => 'ok',
            'fillTxt' => '—',
            // 自動提案スコアは未実装＝暫定（通算回数を 0〜99 に丸めた並び）。
            'score' => min(99, $exp),
            'in' => $in,
            'reason' => [
                ['+', $reasonText],
                ['+', "通算 {$exp}回（スコアは暫定：自動提案は今後実装）"],
            ],
        ];
    }
}
