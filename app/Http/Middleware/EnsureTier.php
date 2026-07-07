<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 権限（4段階）で画面・操作を制限するミドルウェア。
 * 使い方：ルートに middleware('tier:employee') のように「必要な最低ランク」を渡す。
 *
 * ランクの高さ（数字が大きいほど強い）：
 *   staff(スタッフ)=1 < employee(社員)=2 < manager(管理者)=3 < admin(Administrator)=4
 *
 * 足りないとき：
 *   ・スタッフ → 自分のスタッフ画面(/staff-portal)へ戻す（社員向け画面は見せない）
 *   ・社員・管理者が Administrator専用の操作に触れた → 403（権限がありません）
 */
class EnsureTier
{
    private const LEVELS = [
        'staff' => 1,
        'employee' => 2,
        'manager' => 3,
        'admin' => 4,
    ];

    public function handle(Request $request, Closure $next, string $min): Response
    {
        $user = Auth::user();
        $perm = $user->permission ?? 'staff';

        $need = self::LEVELS[$min] ?? 99;
        $have = self::LEVELS[$perm] ?? 0;

        if ($have < $need) {
            // スタッフは社員向け画面に入れない → 自分のスタッフ画面へ
            if ($perm === 'staff') {
                return redirect('/staff-portal');
            }
            // 社員・管理者が Administrator専用操作に触れた
            abort(403, 'この操作を行う権限がありません。');
        }

        return $next($request);
    }
}
