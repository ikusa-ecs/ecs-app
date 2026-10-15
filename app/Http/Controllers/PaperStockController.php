<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Models\PaperOrder;
use App\Support\OfficeScope;
use App\Support\PaperStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 謎解きの紙（印刷物）在庫（/paper-stock）。
 *
 * ・必要数(今後)・消費数(開催済み)は案件データから毎回自動計算して表示。
 * ・入庫数だけは人が手入力して保存する（content_paper_stocks）。
 * ・2026-10-15〜 在庫は「コンテンツ×拠点」（紙は拠点ごとに別々に置いている・baba回答）。
 *   拠点を選ぶ＝その拠点の在庫を見る・入庫数を直す／全拠点＝コンテンツ×拠点の在庫を見比べる（見るだけ）。
 */
class PaperStockController extends Controller
{
    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $data = (new PaperStockService())->compute($office);

        // コンテンツID→名前（見出し用）。
        // 並びはマスタ管理の台帳と同じ（並び順→ID順）＝画面によって並びが変わらないように。
        $names = Content::where('needs_paper', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_name', 'id');

        // 明細の絞り込み（コンテンツ・月）。明細は案件が増えるほど長くなるため。
        $fContent = trim((string) $request->query('content', ''));
        $fMonth = trim((string) $request->query('month', ''));
        $detail = array_values(array_filter($data['detail'], fn ($d) => ($fContent === '' || $d['contentId'] === $fContent)
            && ($fMonth === '' || $d['ym'] === $fMonth)));

        // 追加発注（2026-10-15）＝発注中は全部・届いたものは新しい順に10件（拠点を選んでいればその拠点だけ）。
        $orderRow = fn (PaperOrder $o) => [
            'id' => $o->id,
            'content' => (string) ($names[$o->content_id] ?? $o->content_id),
            'office' => (string) $o->office,
            'count' => $o->count,
            'ordered' => $o->ordered_on?->format('n/j'),
            'expected' => $o->expected_on?->format('n/j'),
            'late' => $o->arrived_on === null && $o->expected_on !== null && $o->expected_on->lt(today()),
            'arrived' => $o->arrived_on?->format('n/j'),
            'note' => (string) ($o->note ?? ''),
            'by' => (string) ($o->ordered_by ?? ''),
            'arrivedBy' => (string) ($o->arrived_by ?? ''),
        ];
        $orderQ = fn () => PaperOrder::query()->when($office !== null, fn ($q) => $q->where('office', $office));
        $pendingOrders = $orderQ()->pending()->orderBy('ordered_on')->orderBy('id')->get()->map($orderRow)->all();
        $arrivedOrders = $orderQ()->whereNotNull('arrived_on')->orderByDesc('arrived_on')->orderByDesc('id')->limit(10)->get()->map($orderRow)->all();

        // タブ（2026-10-15 baba「縦に長くて使いづらい」）＝在庫・発注／月ごと／明細。はじめは在庫・発注。
        $tab = in_array($request->query('tab'), ['month', 'detail'], true) ? $request->query('tab') : 'stock';

        return view('paper_stock', [
            'tab' => $tab,
            'pendingOrders' => $pendingOrders,
            'arrivedOrders' => $arrivedOrders,
            'stock' => $data['stock'],
            'offices' => $data['offices'],
            'byOffice' => $data['byOffice'],
            'months' => $data['months'],
            'byContentMonth' => $data['byContentMonth'],
            'byOfficeMonth' => $data['byOfficeMonth'],
            'detail' => $detail,
            'detailAll' => count($data['detail']),
            'fContent' => $fContent,
            'fMonth' => $fMonth,
            'totals' => $data['totals'],
            'names' => $names,
            'teamSize' => PaperStockService::TEAM_SIZE,
            'alertBelow' => PaperStockService::ALERT_BELOW,
            'officeScope' => $office,
            // 入庫数を直せるのは拠点を1つ選んでいるときだけ（全拠点では「どの拠点の紙か」が決まらない）。
            'showStockCols' => $office !== null,
        ]);
    }

    /** 入庫数（手入力）を、選んでいる拠点のぶんとして保存する。 */
    public function updateReceipts(Request $request)
    {
        // ⚠ POSTは ?office= ではなくフォームの値で拠点を受け取る（下見と保存で拠点がズレないように）。
        //   一般社員は自分の拠点に固定される（fromValue の決まり）。
        $office = OfficeScope::fromValue($request->input('office'));
        if ($office === null) {
            return redirect('/paper-stock?office='.OfficeScope::ALL)
                ->with('status', '入庫数は拠点を選んでから保存してください。');
        }

        $received = $request->input('received', []);   // [content_id => 数]
        if (is_array($received)) {
            foreach ($received as $cid => $val) {
                // 空欄＝まだ入れない（在庫を管理していない）。0 と区別する＝0部なら120部切れの知らせが出る。
                if ($val === '' || $val === null) {
                    continue;
                }
                ContentPaperStock::updateOrCreate(
                    ['content_id' => (string) $cid, 'office' => $office],
                    ['received_count' => max(0, (int) $val)]
                );
            }
        }

        return redirect('/paper-stock?office='.urlencode($office))->with('status', $office.'の入庫数を保存しました。');
    }

    /** 追加発注を記録する（2026-10-15）。在庫にはまだ足さない＝届いてから足す。 */
    public function storeOrder(Request $request)
    {
        $office = OfficeScope::fromValue($request->input('office'));
        if ($office === null) {
            return redirect('/paper-stock?office='.OfficeScope::ALL)
                ->with('status', '発注は拠点を選んでから記録してください。');
        }

        $v = $request->validate([
            'content_id' => ['required', 'string', 'exists:contents,id'],
            'count' => ['required', 'integer', 'min:1', 'max:99999'],
            'expected_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['content_id' => 'コンテンツ', 'count' => '部数', 'expected_on' => '届く予定日']);

        PaperOrder::create($v + [
            'office' => $office,
            'ordered_on' => today(),
            'ordered_by' => (string) (Auth::user()->name ?? ''),
        ]);

        return redirect('/paper-stock?office='.urlencode($office))
            ->with('status', $office.'の発注（'.$v['count'].'部）を記録しました。届いたら「届いた」を押してください。');
    }

    /** 届いた＝その拠点の入庫数に部数を足す（2026-10-15）。二度押しても二重に足さない。 */
    public function arriveOrder(Request $request, int $id)
    {
        $msg = DB::transaction(function () use ($id) {
            $o = PaperOrder::lockForUpdate()->findOrFail($id);
            $this->guardOffice($o);
            if ($o->arrived_on !== null) {
                return 'この発注は、もう「届いた」になっています。';
            }

            $s = ContentPaperStock::firstOrNew(['content_id' => $o->content_id, 'office' => $o->office]);
            $s->received_count = (int) ($s->received_count ?? 0) + $o->count;
            $s->save();

            $o->update(['arrived_on' => today(), 'arrived_by' => (string) (Auth::user()->name ?? '')]);

            return $o->office.'の入庫数に'.$o->count.'部を足しました（入庫数 '.$s->received_count.'部）。';
        });

        return redirect('/paper-stock?office='.urlencode((string) PaperOrder::find($id)?->office))->with('status', $msg);
    }

    /** 発注の記録を取り消す（まだ届いていないものだけ・入れ間違い用）。 */
    public function cancelOrder(Request $request, int $id)
    {
        $o = PaperOrder::findOrFail($id);
        $this->guardOffice($o);
        if ($o->arrived_on !== null) {
            return back()->with('status', '届いた発注は取り消せません（入庫数を直してください）。');
        }
        $o->delete();

        return redirect('/paper-stock?office='.urlencode($o->office))->with('status', '発注の記録を取り消しました。');
    }

    /** 自分の拠点しか見られない人が、よその拠点の発注を触れないように。 */
    private function guardOffice(PaperOrder $o): void
    {
        if (! OfficeScope::canSeeAll()) {
            abort_unless($o->office === (OfficeScope::fromValue(null) ?? ''), 403);
        }
    }
}
