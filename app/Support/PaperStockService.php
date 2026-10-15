<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Models\PaperOrder;
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
 *   ・開催済みの消費は、その「コンテンツ×拠点」の入庫数を**はじめて保存した日**以降の案件だけ数える（2026-10-09）。
 *
 * 拠点（2026-10-15 baba要望）：
 *   ・紙は拠点ごとに別々に置いている＝在庫は「コンテンツ×拠点」で持つ。
 *   ・案件の紙をどこの在庫から減らすか＝{@see paperOffice()}。
 *     **巻き取られた案件は巻き取った拠点**（運営する拠点が紙も用意する・baba回答）、
 *     それ以外は登録した拠点（空なら東京）。**ヘルプは人を出すだけなので紙は動かない。**
 *     ⚠ 一覧に出す条件（OfficeScope::applyToProjects＝ヘルプも含む）とは**わざと違う**。
 */
class PaperStockService
{
    /** チーム数が空のとき、何人で1チームとみなして人数から推定するか。 */
    public const TEAM_SIZE = 6;

    /** 在庫（いま手元にある数）がこれを下回ったらチャットワークで知らせる（2026-10-15 baba）。 */
    public const ALERT_BELOW = 120;

    /** その案件の紙を、どこの拠点の在庫から減らすか。 */
    public static function paperOffice(Project $p): string
    {
        $take = $p->shares->firstWhere('kind', '巻き取り');
        if ($take && trim((string) $take->office) !== '') {
            return trim((string) $take->office);
        }

        return trim((string) ($p->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
    }

    /**
     * すべてを集計して、画面表示に必要な形で返す。
     *
     * @param  string|null  $office  null＝全拠点
     * @return array{
     *   offices: list<string>,
     *   stock: array<int, array<string, mixed>>,
     *   byOffice: array<string, array<string, array<string, mixed>>>,
     *   months: array<int, string>,
     *   byContentMonth: array<string, array<string, int>>,
     *   byOfficeMonth: array<string, array<string, int>>,
     *   detail: array<int, array<string, mixed>>,
     *   totals: array<string, int>,
     * }
     */
    public function compute(?string $office = null): array
    {
        $today = Carbon::today();

        // 紙が必要なコンテンツ（ID→情報）。表示は常にこの並び。
        $paperContents = Content::where('needs_paper', true)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        // 入庫数（手入力）を [拠点][content_id] で引けるように
        $stockRows = ContentPaperStock::get(['content_id', 'office', 'received_count', 'created_at']);
        $received = [];
        // 消費を数えはじめる日＝その「コンテンツ×拠点」の入庫数を**はじめて保存した日**（2026-10-09 baba決定）。
        // ⚠ 過去のアサイン表（2023年〜）を取り込むと、昔の案件の消費まで引かれて在庫が大きく減って見えるため。
        //   入庫数をまだ入れていないところは「今日から」＝開催済みの消費を引かない。
        $countFrom = [];
        foreach ($stockRows as $r) {
            $o = (string) $r->office;
            $received[$o][(string) $r->content_id] = (int) $r->received_count;
            $countFrom[$o][(string) $r->content_id] = $r->created_at ? $r->created_at->copy()->startOfDay() : $today;
        }
        // 発注中（まだ届いていない）の部数を [拠点][content_id] で（2026-10-15）。在庫には足さない＝届いてから足す。
        $ordered = [];
        foreach (PaperOrder::pending()->get(['content_id', 'office', 'count']) as $o) {
            $ordered[(string) $o->office][(string) $o->content_id] = ($ordered[(string) $o->office][(string) $o->content_id] ?? 0) + (int) $o->count;
        }

        $earliest = $today;
        foreach ($countFrom as $perContent) {
            foreach ($perContent as $d) {
                $earliest = $d->lt($earliest) ? $d : $earliest;
            }
        }

        // 並べる拠点＝有効な拠点＋入庫数が入っている拠点（拠点マスタから外れても在庫は見えるように）
        $offices = OfficeScope::options();
        foreach (array_merge(array_keys($received), array_keys($ordered)) as $o) {
            if ($o !== '' && ! in_array($o, $offices, true)) {
                $offices[] = $o;
            }
        }

        // 集計の入れ物
        $use = [];              // [拠点][cid] => ['future'=>, 'past'=>]
        $byContentMonth = [];   // [cid][YYYY-MM] => 枚数（選んだ拠点だけ）
        $byOfficeMonth = [];    // [拠点][YYYY-MM] => 枚数（全拠点）
        $monthsSet = [];        // YYYY-MM の集合
        $detail = [];           // 明細行（選んだ拠点だけ）

        // ⚠ 数えはじめる日より前の案件は読まない（過去の取込で何千件にもなるため）。
        $projects = Project::query()->with('shares')
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '>=', $earliest->format('Y-m-d')))
            ->orderBy('start_date')->get();
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

            $po = self::paperOffice($p);
            if (! in_array($po, $offices, true)) {
                $offices[] = $po;
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
                // 開催済みでも、その「コンテンツ×拠点」の数えはじめる日より前なら数えない（明細にも出さない）。
                if (! $isFuture && $ev && $ev->lt($countFrom[$po][(string) $cid] ?? $today)) {
                    continue;
                }
                $c = $paperContents[$cid];
                $perTeam = max(1, (int) ($c->sheets_per_team ?? 1));
                $sheets = $teams !== null ? (int) ceil($teams) * $perTeam : 0;

                $use[$po][$cid] ??= ['future' => 0, 'past' => 0];
                $use[$po][$cid][$isFuture ? 'future' : 'past'] += $sheets;
                $byOfficeMonth[$po][$ym] = ($byOfficeMonth[$po][$ym] ?? 0) + $sheets;
                $monthsSet[$ym] = true;

                if ($office !== null && $po !== $office) {
                    continue;   // ここから下は選んだ拠点のぶんだけ
                }

                $byContentMonth[$cid][$ym] = ($byContentMonth[$cid][$ym] ?? 0) + $sheets;

                $detail[] = [
                    'status' => $isFuture ? '今後' : '開催済み',
                    'ym' => $ym,
                    'dateStr' => $ev ? $ev->format('n/j') : '未定',
                    'sortDate' => $ev ? $ev->format('Y-m-d') : '9999-99-99',
                    'content' => (string) $c->content_name,
                    'contentId' => $cid,
                    'office' => $po,
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

        // 拠点ごとの在庫表（紙が必要な全コンテンツを固定順で）
        $byOffice = [];
        foreach ($offices as $o) {
            foreach ($paperContents as $cid => $c) {
                $byOffice[$o][$cid] = $this->row(
                    $c,
                    $use[$o][$cid] ?? ['future' => 0, 'past' => 0],
                    $received[$o][(string) $cid] ?? null,
                    $ordered[$o][(string) $cid] ?? 0
                );
            }
        }

        // 選んだ拠点の在庫表。全拠点のときは拠点を足し合わせた数（目安）。
        $rows = [];
        $totals = ['future' => 0, 'past' => 0, 'received' => 0, 'zaiko' => 0, 'shortage' => 0];
        foreach ($paperContents as $cid => $c) {
            if ($office !== null) {
                $r = $byOffice[$office][$cid] ?? $this->row($c, ['future' => 0, 'past' => 0], null);
            } else {
                $sum = ['future' => 0, 'past' => 0];
                $recv = null;
                $ord = 0;
                foreach ($offices as $o) {
                    $sum['future'] += $use[$o][$cid]['future'] ?? 0;
                    $sum['past'] += $use[$o][$cid]['past'] ?? 0;
                    $ord += $ordered[$o][(string) $cid] ?? 0;
                    if (isset($received[$o][(string) $cid])) {
                        $recv = ($recv ?? 0) + $received[$o][(string) $cid];
                    }
                }
                $r = $this->row($c, $sum, $recv, $ord);
            }
            $rows[] = $r;

            $totals['future'] += $r['future'];
            $totals['past'] += $r['past'];
            $totals['received'] += $r['received'];
            $totals['zaiko'] += $r['zaiko'];
            $totals['shortage'] += $r['short'];
        }

        return [
            'offices' => array_values($offices),
            'stock' => $rows,
            'byOffice' => $byOffice,
            'months' => $months,
            'byContentMonth' => $byContentMonth,
            'byOfficeMonth' => $byOfficeMonth,
            'detail' => $detail,
            'totals' => $totals,
        ];
    }

    /**
     * 在庫表の1行。
     *
     * @param  array{future:int, past:int}  $use
     * @param  int|null  $recv  入庫数（null＝まだ入れていない）
     * @param  int  $ordered  発注中（まだ届いていない）の部数
     */
    private function row(Content $c, array $use, ?int $recv, int $ordered = 0): array
    {
        $r = (int) ($recv ?? 0);
        $zaiko = $r - $use['past'];        // 在庫＝入庫−消費
        $excess = $zaiko - $use['future'];  // 過不足＝在庫−必要数(今後)

        return [
            'id' => (string) $c->id,
            'name' => (string) $c->content_name,
            'perTeam' => max(1, (int) ($c->sheets_per_team ?? 1)),
            'future' => $use['future'],
            'past' => $use['past'],
            'received' => $r,
            'hasReceived' => $recv !== null,
            'ordered' => $ordered,
            'zaiko' => $zaiko,
            'excess' => $excess,
            'short' => $excess < 0 ? -$excess : 0,
            // 120部を切っている（入庫数を入れているところだけ。入れていない＝在庫を管理していない）
            'low' => $recv !== null && $zaiko < self::ALERT_BELOW,
        ];
    }
}
