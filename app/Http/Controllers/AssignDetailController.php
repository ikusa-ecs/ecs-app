<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * アサイン画面（案件詳細）/assign-detail。
 *
 * ?case=<案件ID> で開き、案件ヘッダー・提案チーム（＝実際のアサイン）・代替候補（＝応募者）を
 * 本物のデータ（DB）から作って画面に渡す。Blade は window.ECS_DETAIL があればそれを使い、
 * 無ければ従来の見本（cases.js の水合戦サンプル）で動く（フォールバック）。
 *
 * ※自動提案スコアはまだ本実装しない＝暫定（通算回数ベースの並び）。稼働率は稼働状況と同じ
 *   単一ソース（StaffStatusController::buildStatus）から取る。ポジション編集・確定/公開は
 *   この画面では従来どおり見本（保存は別画面：手動アサイン・D決め・公開ボード）。
 */
class AssignDetailController extends Controller
{
    /** 役割コード → この画面の表示ラベル（POSITIONS と対応）。 */
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

    public function show(Request $request)
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
