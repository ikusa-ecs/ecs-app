<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 初回ログインの初期設定（パスワード変更＋プロフィール入力）がまだの人を、
 * 初期設定ページ(/onboarding)へ誘導するミドルウェア。
 *
 * ・people.must_onboard = true（管理者が発行したての人）で、まだ設定を終えていない間は
 *   どの画面を開こうとしても /onboarding に戻す。
 * ・設定が終わると must_onboard を false にする（＝以降は普通に使える）。
 * ・テスト用アカウントはDB保存できないため、セッション ecs_onboarded で「済み」を覚える。
 * ・/onboarding 自身とログアウトは対象外（戻し続ける無限ループを防ぐ）。
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user
            && $user->must_onboard
            && ! session('ecs_onboarded')
            && ! $request->is('onboarding')
            && ! $request->is('logout')
        ) {
            // ⚠ 画面の中からの保存は JSON を待っている。HTML を返すと画面側が中身を読めず、
            //   意味の分からないお知らせになる（EnsureTwoFactor と同じ理由・2026-08-28）。
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'reauth' => true,
                    'message' => '初回の設定がまだ終わっていません。画面を読み込み直して、初期設定を終えてください。',
                ], 409);
            }

            return redirect('/onboarding');
        }

        return $next($request);
    }
}
