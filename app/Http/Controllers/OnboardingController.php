<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\TestAccounts;

/**
 * 初回ログインの初期設定ページ。
 *
 * 管理者が発行したてのアカウント（people.must_onboard = true）が初めてログインしたとき、
 * ここで「パスワードの設定」と「本人情報（身長・靴/衣装サイズ・都道府県・最寄り駅など）」を
 * まとめて入力してもらう。もともと自己登録(/register)で集めていた項目を、
 * 発行済みアカウントの本人が初回ログイン時に入れる運用に置き換えるためのもの。
 *
 * 設定が終わると must_onboard を false にして、通常画面（社員＝ダッシュボード／スタッフ＝ポータル）へ進む。
 */
class OnboardingController extends Controller
{
    /** 初期設定フォームを表示。 */
    public function show()
    {
        return view('onboarding', ['me' => Auth::user()]);
    }

    /** 入力を保存して初期設定を完了する。 */
    public function complete(Request $request)
    {
        $user = Auth::user();

        // 入力チェック（氏名必須・新パスワードは8文字以上＋確認一致）
        $request->validate([
            'name'     => ['required'],
            'email'    => ['nullable', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ], [], [
            'name'     => '氏名',
            'email'    => 'メールアドレス',
            'password' => '新しいパスワード',
        ]);

        // テスト用アカウントはDBに実体が無い → セッションで「設定済み」にしてデモを先へ進める。
        if (TestAccounts::isTest($user)) {
            session(['ecs_onboarded' => true]);

            return redirect($this->homeFor($user))
                ->with('status', 'デモ用アカウントのため保存はされませんが、実際のスタッフはここで入力した内容が登録されます。設定完了として先へ進みます。');
        }

        // パスワード（モデルのキャストで自動ハッシュ化される）
        $user->password = $request->input('password');

        // 基本情報（旧・新規登録の項目）
        $user->name            = $request->input('name');
        $user->email           = $request->input('email') ?: $user->email;
        $user->office          = $request->input('office');
        $user->height          = $request->input('height');
        $user->shoe_size       = $request->input('shoe_size');
        $user->shirt_size      = $request->input('shirt_size');
        $user->prefecture      = $request->input('prefecture');
        $user->nearest_station = $request->input('nearest_station');

        // スタッフの一言アピール（任意）
        if ($user->role === 'staff') {
            $user->appeal = $request->input('appeal');
        }

        // 初回設定 完了
        $user->must_onboard = false;
        $user->save();

        session(['ecs_onboarded' => true]);

        return redirect($this->homeFor($user))
            ->with('status', '初期設定が完了しました。ようこそ！ プロフィールはいつでも「マイプロフィール」から直せます。');
    }

    /** ログイン後の入口（スタッフ＝ポータル／社員＝ダッシュボード）。 */
    private function homeFor($user): string
    {
        return ($user->role === 'staff') ? '/staff-portal' : '/dashboard';
    }
}
