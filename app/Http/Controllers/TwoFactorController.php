<?php

namespace App\Http\Controllers;

use App\Support\TestAccounts;
use Illuminate\Support\Facades\Auth;

/**
 * 2段階認証（2FA）の設定ページ。
 * 本人が自分で「有効化 → QRコードを認証アプリに登録 → コードで確認 → 有効」までを行う。
 * 実際のON/OFF・確認・リカバリコード再発行の処理は Fortify の /user/two-factor-* が担当し、
 * ここはその状態を読み取って画面に出すだけ。
 */
class TwoFactorController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // テスト用ログイン（DBに保存しないアカウント）は 2FA を保存できないので設定不可。
        $isTest = TestAccounts::isTest($user);

        // two_factor_secret が入っていれば「有効化の手続き中 or 有効」。
        $enabled = ! $isTest && ! is_null($user->two_factor_secret);
        // two_factor_confirmed_at が入っていれば「本人がコード確認して有効化済み」。
        $confirmed = $enabled && ! is_null($user->two_factor_confirmed_at);

        $qrSvg = null;
        $recoveryCodes = [];

        if ($enabled) {
            // QRコード（認証アプリで読み取る）とリカバリコード（端末紛失時用）。
            $qrSvg = $user->twoFactorQrCodeSvg();
            $recoveryCodes = $user->recoveryCodes();
        }

        return view('two_factor', [
            'isTest' => $isTest,
            'enabled' => $enabled,
            'confirmed' => $confirmed,
            'qrSvg' => $qrSvg,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }
}
