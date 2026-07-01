<?php

namespace App\Http\Controllers;

use App\Support\CountDeadlineReminderService;
use Illuminate\Http\Request;

/**
 * 人数確定リマインド（/count-reminder）。
 * イベント2週間前を迎えた案件を拾い、営業＋Dへチャットワークで知らせる画面。
 *
 * ・GET  /count-reminder        … 対象案件の一覧を表示（送信はしない）
 * ・POST /count-reminder/send   … mode=dry/test/live で実行
 *     dry ＝件数確認（送らない）／test＝テスト部屋へ／live＝本番部屋へ
 */
class CountDeadlineReminderController extends Controller
{
    public function __construct(private CountDeadlineReminderService $service)
    {
    }

    public function index()
    {
        $cases = $this->service->collectCases();

        return view('count_deadline_reminder', [
            'cases' => $cases,
            'daysBefore' => CountDeadlineReminderService::DAYS_BEFORE,
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

        return redirect('/count-reminder')->with('reminderResult', $result);
    }
}
