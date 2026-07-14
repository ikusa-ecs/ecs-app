<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * ログイン成功後の行き先。
 * ・スタッフ本人 → 自分用のスタッフ画面（/staff-portal）
 * ・社員／管理者／Administrator → 業務ダッシュボード（/dashboard）
 * 従来の AuthController@homeFor と同じ振り分けを Fortify のログインでも保つ。
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        $home = ($user && $user->role === 'staff') ? '/staff-portal' : '/dashboard';

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended($home);
    }
}
