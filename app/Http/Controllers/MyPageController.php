<?php

namespace App\Http\Controllers;

use App\Support\PersonalCases;
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

        return view('mypage', [
            'me' => PersonalCases::meInfo($me),
            'cases' => PersonalCases::cases($today),
            'myAssign' => PersonalCases::myAssign($me),
        ]);
    }
}
