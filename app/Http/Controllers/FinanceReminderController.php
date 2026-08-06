<?php

namespace App\Http\Controllers;

use App\Support\FinanceAccess;
use App\Support\FinanceReminderService;
use Illuminate\Http\Request;

/**
 * 収支未入力リマインド（/finance-reminder）。
 * 締切（イベント終了後3営業日）を過ぎても収支が未入力の案件を拾い、
 * D（ディレクター）へチャットワークで期限つきタスクを付ける画面。
 *
 * ・GET  /finance-reminder        … 対象案件の一覧を表示（送信はしない）
 * ・POST /finance-reminder/send   … mode=dry/test/live で実行
 *     dry ＝件数確認（送らない）／test＝テスト部屋へ／live＝本番部屋へ
 *
 * 作りは人数確定リマインド（/count-reminder）と同じ。
 */
class FinanceReminderController extends Controller
{
    public function __construct(private FinanceReminderService $service)
    {
    }

    public function index()
    {
        return view('finance_reminder', [
            'cases' => $this->service->collectCases(),
            'bizDays' => FinanceAccess::DEADLINE_BIZ_DAYS,
            'hasToken' => ! empty(config('services.chatwork.token')),
            'room' => config('services.chatwork.room'),
            'testRoom' => config('services.chatwork.test_room'),
            'result' => session('reminderResult'),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'mode' => ['required', 'in:dry,test,live'],
        ]);

        $result = $this->service->run($data['mode']);

        return redirect('/finance-reminder')->with('reminderResult', $result);
    }
}
