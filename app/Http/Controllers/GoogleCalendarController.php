<?php

namespace App\Http\Controllers;

use App\Support\GoogleCalendarSync;
use App\Support\MyCalendarEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * マイページの「Googleカレンダーに登録」（2026-11-02 baba決定）。中身の正本＝App\Support\GoogleCalendarSync。
 * 予定の組み立ての正本＝App\Support\MyCalendarEvents（.ics のダウンロードも同じもの）。
 * 【使う人】社員以上（ルートのグループが tier:employee）。自分の予定だけ。
 */
class GoogleCalendarController extends Controller
{
    /** 「Googleカレンダーと連携」→ Googleの「許可」画面へ。 */
    public function connect(Request $request)
    {
        if (! GoogleCalendarSync::configured()) {
            return redirect('/mypage')->with('status', 'Googleカレンダーとの連携は、まだ準備中です（管理者の設定待ち）。');
        }
        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away(GoogleCalendarSync::authUrl($state, (string) (Auth::user()->email ?? '')));
    }

    /** Googleから戻ってくる所。 */
    public function callback(Request $request)
    {
        $state = (string) $request->session()->pull('google_calendar_state', '');
        if ($state === '' || ! hash_equals($state, (string) $request->query('state', ''))) {
            return redirect('/mypage')->with('status', '⚠ 連携をやり直してください（画面を開き直してから、もう一度「Googleカレンダーと連携」を押してください）。');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/mypage')->with('status', 'Googleカレンダーとの連携はしませんでした。');
        }
        try {
            $link = GoogleCalendarSync::connect(Auth::user(), (string) $request->query('code'));
        } catch (\Throwable $e) {
            report($e);

            return redirect('/mypage')->with('status', '⚠ '.$e->getMessage());
        }

        return redirect('/mypage')->with('status', '✅ Googleカレンダーと連携しました'.($link->google_email ? '（'.$link->google_email.'）' : '')
            .'。「📅 今後をまとめて登録」から「Googleカレンダーに登録」を押してください。');
    }

    /** 今日から先のアサインをGoogleカレンダーへ書く（AJAX）。 */
    public function sync()
    {
        try {
            // dup＝同じ時刻の予定がすでにあるとき：ask（聞き返す・はじめ）／skip（飛ばす）／add（かぶっても入れる）
            $dup = in_array(request('dup'), ['skip', 'add'], true) ? request('dup') : 'ask';
            $c = GoogleCalendarSync::sync(Auth::user(), null, $dup);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $c);
    }

    /** 連携をやめる。 */
    public function disconnect()
    {
        GoogleCalendarSync::disconnect(Auth::user());

        return redirect('/mypage')->with('status', 'Googleカレンダーとの連携をやめました（登録した予定はカレンダーに残っています）。');
    }

    /** 予定のファイル（.ics）＝Googleとつながない人の予備の道。中身は Googleへの登録と同じ。 */
    public function ics()
    {
        $body = MyCalendarEvents::ics(MyCalendarEvents::for(Auth::user()));

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"ECS_assign.ics\"; filename*=UTF-8''".rawurlencode('ECS_アサイン_'.now()->format('Ymd').'.ics'),
        ]);
    }
}
