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
            // ⚠ 画面の中からの保存（エントリー・稼働希望など）は JSON を待っている。
            //   そこへログインの画面（HTML）を返すと、画面側は中身を読めず
            //   「保存に失敗しました（SyntaxError: Unexpected token '<' …）」という
            //   意味の分からないお知らせになる（2026-08-28 baba報告：スタッフ画面で発生）。
            //   何が起きたのかを日本語で返して、画面が案内を出せるようにする。
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'reauth' => true,
                    'message' => 'ログインの有効期限が切れました。画面を読み込み直して、ログインし直してください。',
                ], 401);
            }

            return redirect()->route('otp.challenge');
        }

        return $next($request);
    }
}
