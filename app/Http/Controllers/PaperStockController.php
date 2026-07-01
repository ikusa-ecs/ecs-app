<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Support\PaperStockService;
use Illuminate\Http\Request;

/**
 * 謎解きの紙（印刷物）在庫（/paper-stock）。
 *
 * ・必要数(今後)・消費数(開催済み)は案件データから毎回自動計算して表示。
 * ・入庫数だけは人が手入力して保存する（content_paper_stocks）。
 */
class PaperStockController extends Controller
{
    public function index()
    {
        $data = (new PaperStockService())->compute();

        // コンテンツID→名前（ダッシュボードの見出し用）
        $names = Content::where('needs_paper', true)->pluck('content_name', 'id');

        return view('paper_stock', [
            'stock' => $data['stock'],
            'months' => $data['months'],
            'byContentMonth' => $data['byContentMonth'],
            'detail' => $data['detail'],
            'totals' => $data['totals'],
            'names' => $names,
            'teamSize' => PaperStockService::TEAM_SIZE,
        ]);
    }

    /** 入庫数（手入力）を保存する。 */
    public function updateReceipts(Request $request)
    {
        $received = $request->input('received', []);   // [content_id => 数]
        if (is_array($received)) {
            foreach ($received as $cid => $val) {
                $n = ($val === '' || $val === null) ? 0 : max(0, (int) $val);
                ContentPaperStock::updateOrCreate(
                    ['content_id' => (string) $cid],
                    ['received_count' => $n]
                );
            }
        }

        return redirect('/paper-stock')->with('status', '入庫数を保存しました。');
    }
}
