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
 */
class PaperStockController extends Controller
{
    public function index(Request $request)
    {
        // 拠点で絞る（2026-09-15 baba要望）。
        // ⚠ 絞れるのは「案件から数える数字」だけ＝必要数(今後)・消費数・月別・明細。
        //   **入庫数は紙そのものの枚数で、拠点で分けて管理していない**（コンテンツごとに1つ）。
        //   そのため拠点を選んでいるあいだは、入庫・在庫・過不足の列を出さない
        //   （「東京の在庫」という数字は存在しないのに、あるように見えてしまうため）。
        $office = OfficeScope::filter($request);
        $data = (new PaperStockService())->compute($office);

        // コンテンツID→名前（ダッシュボードの見出し用）。
        // 並びはマスタ管理の台帳と同じ（並び順→ID順）＝画面によって並びが変わらないように。
        $names = Content::where('needs_paper', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_name', 'id');

        return view('paper_stock', [
            'stock' => $data['stock'],
            'months' => $data['months'],
            'byContentMonth' => $data['byContentMonth'],
            'detail' => $data['detail'],
            'totals' => $data['totals'],
            'names' => $names,
            'teamSize' => PaperStockService::TEAM_SIZE,
            'officeScope' => $office,
            // 在庫（入庫・在庫・過不足）を出してよいか＝全拠点で見ているときだけ。
            'showStockCols' => $office === null,
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
