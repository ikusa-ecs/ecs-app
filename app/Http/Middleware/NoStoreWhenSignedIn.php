<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログインしている人の画面は、ブラウザに「写しを取っておかないで」と伝える（2026-10-15 FBシートNo.24・25）。
 *
 * 【起きたこと】
 *   ・No.24：スタッフ画面で 11/1 の案件が 11/3 と表示された（更新すると直る）＝数日前のページの写しが出ていた。
 *   ・No.25：マイページを更新すると、一瞬「馬場さん」（別の人）の画面が見えた。
 *   どちらも「前に表示したページの写し」がそのまま出ている見え方。これまで何も指定していなかったので、
 *   ブラウザ（や途中の仕組み）が写しを使い回してよい状態だった。
 *
 * ⚠ 名前・案件・アサインなど人ごとに違う中身なので、写しは取らせない（private, no-store）。
 *   ログインしていない画面（ログイン画面など）は今までどおり。
 */
class NoStoreWhenSignedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (Auth::check() && ! $response->headers->has('Content-Disposition')) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
