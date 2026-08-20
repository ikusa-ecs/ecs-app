<?php

namespace App\Http\Controllers;

use App\Models\StaffNotification;
use App\Support\StaffNotifyService;
use Illuminate\Http\Request;

/**
 * スタッフへのお知らせ送信（/assign-notify）。
 *
 * 「アサインが確定しました」「募集が出ました」をスタッフ本人にメールで知らせる画面。
 * ⚠ 自動送信はしない（2026-08-20 baba 決定）＝ここで相手と文面を確かめて「送信」を押す。
 *
 *  ・GET  /assign-notify        … 送る候補の一覧＋文面プレビュー（送信はしない）
 *  ・POST /assign-notify/send   … kind（種類）＋ mode（dry/test/live）で実行
 *      dry ＝件数確認だけ／test＝自分にだけ1件送る／live＝対象者へ送る（記録あり）
 *
 * 作りは人数確定リマインド（/count-reminder）・収支リマインド（/finance-reminder）と同じ形。
 */
class StaffNotifyController extends Controller
{
    public function __construct(private StaffNotifyService $service)
    {
    }

    public function index(Request $request)
    {
        $kind = $request->query('kind') === StaffNotifyService::KIND_PUBLISHED
            ? StaffNotifyService::KIND_PUBLISHED
            : StaffNotifyService::KIND_CONFIRMED;

        $cases = $this->service->collect($kind);

        return view('staff_notify', [
            'kind'    => $kind,
            'cases'   => $cases,
            // 画面で使う値はここで作って渡す（Blade に PHP を書くと展開されない罠があるため）。
            'isConfirmed' => $kind === StaffNotifyService::KIND_CONFIRMED,
            'sendableCount' => count(array_filter($cases, fn ($c) => $c['skipReason'] === null)),
            'preview' => $cases[0] ?? null,
            'result'  => session('notifyResult'),
            // 直近の送信記録（同じ知らせを二度送らない仕組みが効いているかを目で確かめる用）。
            'recent'  => StaffNotification::orderByDesc('id')->limit(30)->get(),
            // メールの送り方（ローカルは log＝ファイルに書くだけ。本番は ses）。
            'mailer'  => config('mail.default'),
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', 'in:' . StaffNotifyService::KIND_CONFIRMED . ',' . StaffNotifyService::KIND_PUBLISHED],
            'mode' => ['required', 'in:dry,test,live'],
        ]);

        $result = $this->service->run($data['kind'], $data['mode']);

        return redirect('/assign-notify?kind=' . urlencode($data['kind']))
            ->with('notifyResult', $result);
    }
}
