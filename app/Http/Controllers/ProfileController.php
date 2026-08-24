<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\TestAccounts;

/**
 * マイプロフィール画面。
 *
 * ログイン中の本人（App\Models\Person＝people テーブル）が、自分のプロフィールを
 * 自分で入力・更新するための画面と保存処理。
 * これまで自己登録（/register）で入れていた項目を、発行済みアカウントの本人が
 * ログイン後に自分で埋める運用に置き換えるためのもの。
 *
 * 保存対象は people に実在する列だけ（存在しない身長/都道府県/最寄駅などは扱わない）。
 * 社員（role=employee）とスタッフ（role=staff）で入力項目を出し分ける。
 */
class ProfileController extends Controller
{
    /** 入力・編集フォームを表示。 */
    public function edit()
    {
        return view('profile_edit', ['me' => Auth::user()]);
    }

    /** 入力内容を保存。 */
    public function update(Request $request)
    {
        // 1. ログイン中の本人
        $user = Auth::user();

        // 2. テスト用アカウントはDBに実体が無い/固定なので保存しない
        if (TestAccounts::isTest($user)) {
            return back()->with('status', 'テスト用アカウントはプロフィールを保存できません（見本のため）。');
        }

        // 3. 入力チェック（氏名だけ必須・他は任意）
        $request->validate([
            'name'      => ['required'],
            'name_kana' => ['nullable', 'string', 'max:255'],
            'email'     => ['nullable', 'email'],
            // チャットワークID＝リマインドの宛先。数字のみ（桁が多いので文字列で持つ）。
            'chatwork_id' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]+$/'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'max:50'],
        ], [
            'chatwork_id.regex' => 'チャットワークIDは数字だけで入れてください。',
        ], [
            'name'      => '氏名',
            'name_kana' => 'ふりがな',
            'email'     => 'メールアドレス',
            'chatwork_id' => 'チャットワークID',
            'departments' => '兼務している所属',
        ]);

        // 4. 共通項目（社員・スタッフ両方）
        $user->name      = $request->input('name');
        $user->name_kana = $request->input('name_kana');   // 五十音順の並びに使う
        $user->email     = $request->input('email');
        $user->chatwork_id = $request->input('chatwork_id') ?: null;   // リマインドの宛先
        $user->office = $request->input('office');
        // 身長・靴/服（衣装）サイズ・都道府県・最寄り駅（旧・新規登録の基本情報。当日準備の参考）
        $user->height          = $request->input('height');
        $user->shoe_size       = $request->input('shoe_size');
        $user->shirt_size      = $request->input('shirt_size');
        $user->prefecture      = $request->input('prefecture');
        $user->nearest_station = $request->input('nearest_station');

        // 5. role ごとに対象カラムだけ保存（people に実在する列のみ）
        if ($user->role === 'employee') {
            // 社員だけの項目
            $user->department = $request->input('department');
            // 兼務を含めた所属すべて。主な所属は必ず含める（チェックを外していても入れる）。
            $user->departments = \App\Support\Departments::normalize(
                $request->input('department'),
                (array) $request->input('departments', [])
            );
        } elseif ($user->role === 'staff') {
            // スタッフだけの項目
            $user->appeal            = $request->input('appeal');
            $user->liked_contents    = $request->input('liked_contents');
            $user->disliked_contents = $request->input('disliked_contents');
            $user->strong_positions  = $request->input('strong_positions');
            $user->weak_positions    = $request->input('weak_positions');
            // チェックボックス（boolean 列）は boolean() で確実に true/false にする
            $user->can_stay_over = $request->boolean('can_stay_over');
            $user->can_kigurumi  = $request->boolean('can_kigurumi');
        }

        // 6. 保存して完了メッセージ
        $user->save();

        return back()->with('status', 'プロフィールを保存しました。');
    }

}
