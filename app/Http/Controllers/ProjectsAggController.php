<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;

/**
 * 社員・ディレクター集計（/projects-agg・案件一覧から開く別ウィンドウ）。
 *
 * 各社員が D（ディレクター）／SD（サブD）を何回務めたかを、実施形態・規模別に数える。
 * 元データは「D決め画面（/assign-director）」が保存する assignments（role='D'/'SD'）＝
 * D/SDを決める正式な場所。projects.director_id（旧・計画用の項目）とは別物なので使わない。
 *
 * 数え方（見本と同一の定義）：
 *  ・D計      ＝ Dを務めた案件数。
 *  ・リアルD  ＝ そのうち実施形態が「リアル」（通常＋ロング）の案件。
 *  ・大型D    ＝ リアル かつ 規模が大型 の案件でDを務めた数。
 *  ・大型SD   ＝ リアル かつ 大型 の案件でSDを務めた数。
 *  ・オンラインD＝ 実施形態が「オンライン」の案件でDを務めた数。
 *  ※ 下書き案件は数えない。キャンセルのアサインは除く。
 *  ※ 同じ案件で同じ人が複数日Dに入っていても、その案件は1回として数える。
 */
class ProjectsAggController extends Controller
{
    public function index()
    {
        // D/SD の担当（キャンセル除く）。
        $rows = Assignment::whereIn('role', ['D', 'SD'])
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role']);

        // 関係する案件の実施形態・規模・状態。
        $projects = Project::whereIn('id', $rows->pluck('project_id')->unique())
            ->get(['id', 'format', 'scale', 'status'])
            ->keyBy('id');

        // 担当者（社員）の氏名・所属（部署）。所属は名前の色分けに使う（D決め画面と同じ配色）。
        $people = Person::whereIn('id', $rows->pluck('staff_id')->unique())
            ->get(['id', 'name', 'department'])
            ->keyBy('id');

        $agg = [];     // staff_id => 集計
        $seen = [];    // 案件×人×役割の重複（複数日）を1回にまとめる
        $totalD = 0;   // D担当の総数（全社員ぶん）
        $totalSD = 0;  // SD担当の総数
        $projSet = []; // 対象になった案件IDの集合（重複なし）

        foreach ($rows as $r) {
            $proj = $projects->get($r->project_id);
            if (! $proj || $proj->status === '下書き') {
                continue;   // 案件が無い／下書きは数えない
            }

            $key = $r->project_id.'|'.$r->staff_id.'|'.$r->role;
            if (isset($seen[$key])) {
                continue;   // 同案件・同役割は1回だけ
            }
            $seen[$key] = true;
            $projSet[$r->project_id] = true;
            if ($r->role === 'D') {
                $totalD++;
            } else {
                $totalSD++;
            }

            $fmt = (string) ($proj->format ?? '');
            $isReal = mb_strpos($fmt, 'リアル') !== false;       // 通常＋ロングを含む
            $isOnline = mb_strpos($fmt, 'オンライン') !== false;
            $isBig = ($proj->scale ?? '') === '大型';

            $sid = $r->staff_id;
            if (! isset($agg[$sid])) {
                $dept = (string) ($people[$sid]->department ?? '');
                $agg[$sid] = [
                    'name' => $people[$sid]->name ?? $sid,
                    'dept' => $dept,
                    'deptCls' => $this->deptClass($dept),
                    'total' => 0, 'd' => 0, 'sd' => 0,
                    'realD' => 0, 'bigD' => 0, 'bigSD' => 0, 'onlineD' => 0,
                ];
            }

            if ($r->role === 'D') {
                $agg[$sid]['d']++;
                if ($isReal) {
                    $agg[$sid]['realD']++;
                }
                if ($isOnline) {
                    $agg[$sid]['onlineD']++;
                }
                if ($isReal && $isBig) {
                    $agg[$sid]['bigD']++;
                }
            } elseif ($r->role === 'SD') {
                $agg[$sid]['sd']++;
                if ($isReal && $isBig) {
                    $agg[$sid]['bigSD']++;
                }
            }
        }

        // 個人ごとの担当合計（D＋SD）を入れる。
        foreach ($agg as &$row) {
            $row['total'] = $row['d'] + $row['sd'];
        }
        unset($row);

        // 担当合計の多い順 → D計の多い順（合計を主に見たいので先頭に）。
        $list = array_values($agg);
        usort($list, fn ($a, $b) => ($b['total'] <=> $a['total']) ?: ($b['d'] <=> $a['d']));

        // 「全部で何件入っているか」の合計（画面上部に表示）。
        $summary = [
            'records'  => $totalD + $totalSD,   // D／SD担当の総件数
            'd'        => $totalD,
            'sd'       => $totalSD,
            'staff'    => count($agg),           // 対象になった社員数
            'projects' => count($projSet),       // 対象になった案件数
        ];

        return view('projects_agg', ['rows' => $list, 'summary' => $summary]);
    }

    /** 部署 → 名前の色クラス（D決め画面と同じ：イベプラ=橙/セールス=藍/クリエイティブ=緑）。 */
    private function deptClass(string $dept): string
    {
        return match ($dept) {
            'イベプラ' => 'dep-plan',
            'セールス' => 'dep-sales',
            'クリエイティブ' => 'dep-creative',
            default => '',
        };
    }
}
