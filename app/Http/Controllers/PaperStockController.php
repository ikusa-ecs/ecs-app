<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Support\OfficeScope;
use App\Support\PaperStockService;
use Illuminate\Http\Request;

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

        return view('paper_stock', [
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
}
