<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 謎解きの紙（印刷物）在庫・必要数の集計（GAS「謎解き紙 集計」のECS版）。
 *
 * GAS版との一番の違い：
 *   GASは複数のアサイン表（スプレッドシート）を集めて合算していたが、
 *   ECSは案件データ（projects）が1か所にまとまっているので「集める」作業が要らない。
 *   常に最新の案件から、その場で数え直す。
 *
 * 数え方：
 *   ・対象＝コンテンツで「紙が必要」にしたもの（contents.needs_paper）。
 *   ・チーム数＝案件の team_count。未入力ならお客様人数(guest_count)÷{@see TEAM_SIZE}で推定。
 *   ・必要枚数＝ceil(チーム数) × コンテンツの sheets_per_team（基本1枚）。
 *   ・開催日が今日以降＝「必要数(今後)」、過去＝「消費数(開催済み)」に振り分け。
 *   ・オンライン開催は紙が要らないので除外。下書きは対象外。
 */
class PaperStockService
{
    /** チーム数が空のとき、何人で1チームとみなして人数から推定するか。 */
    public const TEAM_SIZE = 6;

    /**
     * すべてを集計して、画面表示に必要な形で返す。
     *
     * @return array{
     *   stock: array<int, array<string, mixed>>,
     *   months: array<int, string>,
     *   byContentMonth: array<string, array<string, int>>,
     *   detail: array<int, array<string, mixed>>,
     *   totals: array<string, int>,
     * }
     */
    public function compute(): array
    {
        $today = Carbon::today();

        // 紙が必要なコンテンツ（ID→情報）。表示は常にこの並び。
        $paperContents = Content::where('needs_paper', true)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // 入庫数（手入力）を content_id で引けるように
        $received = ContentPaperStock::pluck('received_count', 'content_id');

        // 集計の入れ物
        $stock = [];            // [cid] => ['future'=>, 'past'=>]
        $byContentMonth = [];   // [cid][YYYY-MM] => 枚数
        $monthsSet = [];        // YYYY-MM の集合
        $detail = [];           // 明細行
        foreach ($paperContents as $cid => $c) {
            $stock[$cid] = ['future' => 0, 'past' => 0];
        }

        $projects = Project::orderBy('start_date')->get();
        foreach ($projects as $p) {
            if ($p->status === '下書き') {
                continue;   // 下書きは実在しない案件なので数えない
            }

            // オンライン開催は紙が不要
            $format = (string) ($p->format ?? '');
            if (mb_strpos($format, 'オンライン') !== false) {
                continue;
            }

            $cids = is_array($p->content_ids) ? $p->content_ids : [];
            if (! $cids) {
                continue;
            }

            // チーム数（未入力ならお客様人数から推定）
            $teams = null;
            $estimated = false;
            if ($p->team_count !== null && (int) $p->team_count > 0) {
                $teams = (int) $p->team_count;
            } elseif ($p->guest_count !== null && (int) $p->guest_count > 0) {
                $teams = (int) ceil(((int) $p->guest_count) / self::TEAM_SIZE);
                $estimated = true;
            }

            // 開催日で今後／開催済みを判定（日付なしは今後扱い）
            $start = $p->start_date;
            $ev = $start ? Carbon::parse($start)->startOfDay() : null;
            $isFuture = $ev ? $ev->gte($today) : true;
            $ym = $ev ? $ev->format('Y-m') : '未定';

            foreach ($cids as $cid) {
                if (! isset($paperContents[$cid])) {
                    continue;   // 紙が要らないコンテンツは対象外
                }
                $c = $paperContents[$cid];
                $perTeam = max(1, (int) ($c->sheets_per_team ?? 1));
                $sheets = $teams !== null ? (int) ceil($teams) * $perTeam : 0;

                if ($isFuture) {
                    $stock[$cid]['future'] += $sheets;
                } else {
                    $stock[$cid]['past'] += $sheets;
                }

                if (! isset($byContentMonth[$cid])) {
                    $byContentMonth[$cid] = [];
                }
                $byContentMonth[$cid][$ym] = ($byContentMonth[$cid][$ym] ?? 0) + $sheets;
                $monthsSet[$ym] = true;

                $detail[] = [
                    'status' => $isFuture ? '今後' : '開催済み',
                    'ym' => $ym,
                    'dateStr' => $ev ? $ev->format('n/j') : '未定',
                    'sortDate' => $ev ? $ev->format('Y-m-d') : '9999-99-99',
                    'content' => (string) $c->content_name,
                    'contentId' => $cid,
                    'client' => (string) ($p->client ?? ''),
                    'projectId' => $p->id,
                    'projectName' => (string) ($p->name ?? $p->title ?? ''),
                    'guest' => $p->guest_count,
                    'teamsRaw' => $p->team_count,
                    'teamsCalc' => $teams,
                    'sheets' => $sheets,
                    'estimated' => $estimated,
                ];
            }
        }

        // 月を昇順に（「未定」は最後）
        $months = array_keys($monthsSet);
        sort($months);
        if (($k = array_search('未定', $months, true)) !== false) {
            unset($months[$k]);
            $months[] = '未定';
        }
        $months = array_values($months);

        // 明細を開催日順に
        usort($detail, fn ($a, $b) => strcmp($a['sortDate'], $b['sortDate']));

        // 在庫表の行（紙が必要な全コンテンツを固定順で）
        $rows = [];
        $totals = ['future' => 0, 'past' => 0, 'received' => 0, 'zaiko' => 0, 'shortage' => 0];
        foreach ($paperContents as $cid => $c) {
            $future = $stock[$cid]['future'];
            $past = $stock[$cid]['past'];
            $recv = (int) ($received[$cid] ?? 0);
            $zaiko = $recv - $past;         // 在庫＝入庫−消費
            $excess = $zaiko - $future;     // 過不足＝在庫−必要数(今後)

            $rows[] = [
                'id' => $cid,
                'name' => (string) $c->content_name,
                'perTeam' => max(1, (int) ($c->sheets_per_team ?? 1)),
                'future' => $future,
                'past' => $past,
                'received' => $recv,
                'zaiko' => $zaiko,
                'excess' => $excess,
                'short' => $excess < 0 ? -$excess : 0,
            ];

            $totals['future'] += $future;
            $totals['past'] += $past;
            $totals['received'] += $recv;
            $totals['zaiko'] += $zaiko;
            if ($excess < 0) {
                $totals['shortage'] += -$excess;
            }
        }

        return [
            'stock' => $rows,
            'months' => $months,
            'byContentMonth' => $byContentMonth,
            'detail' => $detail,
            'totals' => $totals,
        ];
    }
}
