<?php

namespace App\Http\Controllers;

use App\Support\PersonalCases;
use Illuminate\Support\Carbon;

/**
 * マイページ・収支入力（/mypage-finance）。
 *
 * これまで画面は cases.js（見本）＋Blade内ベタ書きの MY_ASSIGN で動いていた。
 * ここでは マイページと同じ共通部品（PersonalCases）で
 *   ・自分＝誰か   ・全案件（DB）   ・自分のアサイン（案件ID→役割コード）
 * を渡し、収支の対象案件を本物のデータで出す。
 *
 * 収支の対象＝自分が「D（ディレクター）」の案件、または「営業担当」の案件。
 * ※財務データの実保存はMTG後（今は入力欄＋ブラウザ記憶のモックのまま）。
 */
class MyPageFinanceController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $me = PersonalCases::meModel();

        return view('mypage_finance', [
            'me' => PersonalCases::meInfo($me),
            'cases' => PersonalCases::cases($today),
            'myAssign' => PersonalCases::myAssign($me),
        ]);
    }
}
