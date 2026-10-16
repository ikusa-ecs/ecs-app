<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 退職にした人は、ログイン中でもその場でログアウトさせる（2026-10-16 baba要望）。
 *
 * 【なぜ】退職（people.active=false）にすると新しいログインは止まるが、
 *   すでにログインしている人は、そのまま画面を使い続けられた（ログインの確認は入口だけだったため）。
 *   次に画面を開いた・保存した瞬間にここで気づいて、ログアウトさせる。
 *
 * ⚠ active が空（NULL）の人は在籍あつかい（Person::inService と同じ決まり）。false のときだけ止める。
 * ⚠ 画面の中からの保存（fetch）は JSON を待っているので、JSON で理由を返す（EnsureTwoFactor と同じ形）。
 */
class LogoutRetired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'このアカウントは現在使えません（在籍なし）。ご不明な点は担当者にお問い合わせください。';

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'reauth' => true, 'message' => $message], 401);
            }

            return redirect('/')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
