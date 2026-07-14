<?php

namespace App\Http\Middleware;

use App\Support\TestAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2段階認証（メールでコード）のゲート。
 *
 * ログイン（メール＋パスワード）を通過しても、このセッションでまだ
 * 「メールに届いた確認コード」を入れていない人は、業務画面へ進む前に
 * コード入力ページ（/otp）へ誘導する。コードを入れて session('twofa_ok')=true に
 * なると、以降そのセッション中は通過できる。
 *
 * ・テスト用ログイン（DBに無い開発用アカウント）は対象外＝ワンクリックの確認を妨げない。
 *   本番では ECS_TEST_LOGIN=false でテスト垢自体が無効になるため、実ユーザーは必ず対象。
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && ! TestAccounts::isTest($user)
            && ! $request->session()->get('twofa_ok')) {
            return redirect()->route('otp.challenge');
        }

        return $next($request);
    }
}
