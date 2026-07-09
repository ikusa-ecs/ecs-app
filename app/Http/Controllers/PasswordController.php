<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Support\TestAccounts;

/**
 * パスワード変更画面。
 *
 * ログイン中の本人が自分のパスワードを変更するための画面とその保存処理。
 * 照合先は people テーブル（App\Models\Person）。password 列はモデルのキャストで
 * 代入時に自動でハッシュ化される（'password' => 'hashed'）。
 */
class PasswordController extends Controller
{
    /** 変更フォームを表示。 */
    public function edit()
    {
        return view('password_change');
    }

    /** 変更を保存。 */
    public function update(Request $request)
    {
        // 1. 入力チェック（新しいパスワードは8文字以上・確認欄と一致）
        $request->validate([
            'current_password' => ['required'],
            'password'         => ['required', 'min:8', 'confirmed'],
        ], [], [
            'current_password' => '現在のパスワード',
            'password'         => '新しいパスワード',
        ]);

        // 2. ログイン中の本人
        $user = Auth::user();

        // 3. テスト用アカウントはDBに実体が無い/固定なので変更しない
        if (\App\Support\TestAccounts::isTest($user)) {
            return back()->with('status', 'テスト用アカウントはパスワードを変更できません（見本のため）。');
        }

        // 4. 現在のパスワードが合っているか確認
        if (! Hash::check($request->input('current_password'), (string) $user->password)) {
            return back()->withErrors(['current_password' => '現在のパスワードが違います。']);
        }

        // 5. 変更（キャストで自動ハッシュ化される）
        $user->password = $request->input('password');
        $user->save();

        // 6. 完了メッセージ
        return back()->with('status', 'パスワードを変更しました。');
    }
}
