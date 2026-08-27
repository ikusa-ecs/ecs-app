<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * ログイン（Laravel標準機能を使用）。
 * ※ ログアウト（POST /logout）は Fortify が持っている＝ここには無い。
 * ・照合先は people 名簿（config/auth.php で Person モデルを指定済み）。
 * ・メール＋パスワードで Auth::attempt。合えばセッションにログイン状態を持つ。
 * ・自己登録は廃止方針（管理者が発行）＝ここには登録処理を置かない。
 */
class AuthController extends Controller
{
    /** ログイン画面。すでにログイン済みなら行き先へ飛ばす。 */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect($this->homeFor(Auth::user()));
        }
        return view('login');
    }

    /** ログイン実行。メール＋パスワードを照合する。 */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [], [
            'email' => 'メールアドレス',
            'password' => 'パスワード',
        ]);

        $remember = $request->boolean('remember');

        // active=true（在籍・稼働中）の人だけログインできるようにする。
        if (! Auth::attempt(array_merge($credentials, ['active' => true]), $remember)) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスかパスワードが違います。',
            ]);
        }

        // セッションIDを作り直す（乗っ取り対策の標準作法）。
        $request->session()->regenerate();

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /** ログイン後の行き先：スタッフはスタッフ画面、それ以外は社員ダッシュボード。 */
    private function homeFor($user): string
    {
        return ($user && $user->role === 'staff') ? '/staff-portal' : '/dashboard';
    }
}
