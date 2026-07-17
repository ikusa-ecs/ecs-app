<?php

namespace App\Http\Controllers;

use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * マイページ（S-015 / /mypage）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）と、Blade内ベタ書きの
 * MY_ASSIGN（案件ID→自分のポジション）をブラウザで読んで描いていた。
 * ここでは DB から「ログイン中の社員（＝自分）」の情報を読み、
 *   ① アサインされた案件 … assignments（自分が割り当てられた行）
 *   ② 営業担当の案件   … projects（自分が営業担当の案件）
 * を cases.js と「同じ形」に詰め替えて Blade に渡す。
 *
 * ※認証はMTG後。それまでは「自分」を固定で決める（社員 E-007 / baba）。
 *   DB に該当データが無ければ Blade 側が今までの見本表示にフォールバックする。
 */
class MyPageController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // 「自分」まわりの共通部品（収支入力 /mypage-finance と同じ定義）。
        $me = PersonalCases::meModel();

        // 通知設定（自分の people.notify_settings）。未設定なら全オフを既定にする。
        $saved = ($me && is_array($me->notify_settings)) ? $me->notify_settings : [];
        $notify = [
            'follow'   => (bool) ($saved['follow'] ?? false),
            'assign'   => (bool) ($saved['assign'] ?? false),
            'deadline' => (bool) ($saved['deadline'] ?? false),
        ];

        return view('mypage', [
            'me' => PersonalCases::meInfo($me),
            'cases' => PersonalCases::cases($today),
            'myAssign' => PersonalCases::myAssign($me),
            'notify' => $notify,
        ]);
    }

    /**
     * 通知設定の保存（POST /mypage/notify）。
     *
     * マイページの通知オン・オフ（フォロー所感／アサイン確定／締切間近）を
     * 自分（people）の notify_settings に保存する。
     * 認証はMTG後のため「自分」は PersonalCases::meModel() 固定（E-007/baba）。
     * 自分が見つからない場合は保存だけスキップして正常応答を返す。
     */
    public function saveNotify(Request $request)
    {
        $data = $request->validate([
            'follow'   => 'required|boolean',
            'assign'   => 'required|boolean',
            'deadline' => 'required|boolean',
        ]);

        $me = PersonalCases::meModel();
        if (! $me) {
            return response()->json(['ok' => true, 'saved' => false]);
        }

        $me->notify_settings = [
            'follow'   => (bool) $data['follow'],
            'assign'   => (bool) $data['assign'],
            'deadline' => (bool) $data['deadline'],
        ];
        $me->save();

        return response()->json(['ok' => true]);
    }
}
