<?php

namespace App\Http\Controllers;

use App\Models\SfInboxItem;
use App\Models\SfProduct;
use App\Support\OfficeScope;
use App\Support\SfInboxRegister;
use App\Support\SfOffice;
use App\Support\SfOpportunityMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Salesforceの商談の受信箱（/sf-inbox・管理者以上）。2026-11-16。
 *
 * ⚠ 届いただけでは案件は変わらない。「案件として登録」を押したときだけ projects に1件作る
 *   （中身は App\Support\SfInboxRegister）。見送りは消さずに残す。
 * ⚠ 拠点で絞らない（拠点が読めない「要確認」の商談を、どの拠点の人にも見落とさせないため）。
 */
class SfInboxController extends Controller
{
    public function index(Request $request)
    {
        $show = (string) $request->query('show', SfInboxItem::WAITING);
        if (! in_array($show, [SfInboxItem::WAITING, SfInboxItem::REGISTERED, SfInboxItem::SKIPPED, 'all'], true)) {
            $show = SfInboxItem::WAITING;
        }

        $query = SfInboxItem::query();
        if ($show !== 'all') {
            $query->where('status', $show);
        }
        $items = $query->orderByDesc('received_at')->orderByDesc('id')->get();

        $rows = $items->map(function (SfInboxItem $item) {
            // ⚠ 1件の中身が壊れていても、画面全体は止めない
            try {
                $preview = SfOpportunityMap::preview($item->payload ?? []);
            } catch (\Throwable $e) {
                $preview = null;
            }

            return ['item' => $item, 'p' => $preview];
        });

        $offices = OfficeScope::options();
        if ($offices === []) {
            $offices = SfOffice::ECS_OFFICES;
        }

        return view('sf_inbox', [
            'rows' => $rows,
            'show' => $show,
            'offices' => $offices,
            'counts' => [
                SfInboxItem::WAITING => SfInboxItem::where('status', SfInboxItem::WAITING)->count(),
                SfInboxItem::REGISTERED => SfInboxItem::where('status', SfInboxItem::REGISTERED)->count(),
                SfInboxItem::SKIPPED => SfInboxItem::where('status', SfInboxItem::SKIPPED)->count(),
                'all' => SfInboxItem::count(),
            ],
            'pendingProducts' => SfProduct::where('handling', SfProduct::PENDING)->count(),
        ]);
    }

    /** 案件として登録（このときだけ projects が増える）。 */
    public function register(Request $request, int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        $r = SfInboxRegister::register($item, $request->input('office'), Auth::id());

        return redirect('/sf-inbox')->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** 見送り（消さずに残す）。取り込み待ちのものだけ。 */
    public function skip(int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        if ($item->status !== SfInboxItem::WAITING) {
            return redirect('/sf-inbox')->with('error', '取り込み待ちの商談だけ見送りにできます。');
        }
        $item->update(['status' => SfInboxItem::SKIPPED, 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return redirect('/sf-inbox')->with('status', '見送りにしました（「見送り」のタブに残っています）。');
    }

    /**
     * 取り込み待ちに戻す。見送りのものだけ。
     * ⚠ 登録ずみは戻さない（戻すと同じ商談から案件が2つできる）。
     */
    public function reopen(int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        if ($item->status !== SfInboxItem::SKIPPED) {
            return redirect('/sf-inbox?show='.$item->status)->with('error', '見送りの商談だけ取り込み待ちに戻せます（登録ずみの商談は戻せません）。');
        }
        $item->update(['status' => SfInboxItem::WAITING, 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return redirect('/sf-inbox?show=skipped')->with('status', '取り込み待ちに戻しました。');
    }
}
