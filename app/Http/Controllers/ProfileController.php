<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\ProfileBasics;
use App\Support\ProfileExtras;
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
        //    ⚠ 決まりは書き写さない。正本＝ProfileBasics::RULES（氏名・所属など）と
        //      ProfileExtras::RULES（本人の申告6項目）。
        $request->validate(
            ProfileBasics::RULES + ProfileExtras::RULES,
            ProfileBasics::MESSAGES,
            ProfileBasics::LABELS + ProfileExtras::LABELS
        );

        // 4. 氏名・ふりがな・メール・チャットワークID・事務所・身長・靴/服・都道府県・最寄り駅
        //    ＋（社員のみ）主な所属・兼務。⚠ 保存のしかたはここに書かない。正本＝ProfileBasics。
        ProfileBasics::apply($user, $request->all());

        // 運転・英語・話せる言語・挑戦したい役割・使っているツール・備考（社員もスタッフも同じ）。
        // ⚠ 運転／英語はもともとスタッフ画面の設定タブにしか無かった＝同じ列に保存する（両方から直せる）。
        //   ⚠ 保存のしかたはここに書かない。正本＝App\Support\ProfileExtras（入口が4つあるため）。
        ProfileExtras::apply($user, $request->all());

        // 5. スタッフだけの項目（この画面にしか無い）
        if ($user->role === 'staff') {
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

    /**
     * マイページのカードから、本人の申告6項目だけを保存する（2026-08-31 baba要望）。
     *
     * 【なぜ別の入口を作ったか】
     * 6項目を足したものの、入れる場所が「マイページ →『プロフィールを編集』→ 下までスクロール」
     * でしか無く、**社員が気づけなかった**（babaから指摘）。いちばんよく開くマイページの
     * カードの中で、そのまま選んで保存できるようにする。
     *
     * ⚠ update() は「フォームに出ている項目を全部書き換える」作りなので、
     *   ここから /profile へ送ると**氏名や身長まで空で上書きされる**。だから別の入口にしている。
     *   ここで触るのは ProfileExtras が持つ6＋1列だけ。
     */
    public function updateExtras(Request $request)
    {
        $user = Auth::user();

        if (TestAccounts::isTest($user)) {
            return back()->with('status', 'テスト用アカウントはプロフィールを保存できません（見本のため）。');
        }

        $request->validate(ProfileExtras::RULES, [], ProfileExtras::LABELS);

        ProfileExtras::apply($user, $request->all());
        $user->save();

        return back()->with('status', 'プロフィールを保存しました。');
    }

    /**
     * マイページから「氏名・所属・身長など」だけを保存する（2026-09-01 baba要望）。
     *
     * 【なぜ】「本人の情報は、本人ならマイページから直せるように」（baba）。
     * とくに**入社年月日**は初回の初期設定でしか入れられず、間違えても本人が直せなかった
     * （「入社年月日のつもりで生年月日を入れてしまった」方が出た）。
     *
     * ⚠ ここで触るのは ProfileBasics が持つ列だけ。
     *   スタッフだけの項目（一言アピール等）と本人の申告6項目には触らない
     *   ＝マイページに出していない欄を、空で消してしまわないため。
     */
    public function updateBasic(Request $request)
    {
        $user = Auth::user();

        if (TestAccounts::isTest($user)) {
            return back()->with('status', 'テスト用アカウントはプロフィールを保存できません（見本のため）。');
        }

        $request->validate(ProfileBasics::RULES, ProfileBasics::MESSAGES, ProfileBasics::LABELS);

        ProfileBasics::apply($user, $request->all());
        $user->save();

        return back()->with('status', '氏名・所属などを保存しました。');
    }
}
