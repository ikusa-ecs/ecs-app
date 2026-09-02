<?php

namespace App\Support;

use App\Models\Person;

/**
 * 本人が入れる「氏名・所属・身長など」の保存の正本（2026-09-01）。
 *
 * 対象＝氏名／ふりがな／メール／チャットワークID／事務所／身長／靴のサイズ／服のサイズ／
 *       都道府県／最寄り駅／（社員のみ）主な所属・兼務している所属。
 *
 * 【なぜ要るか】
 * ⚠ 入れられる画面が2つになった（マイプロフィール `/profile` と、**マイページ**）。
 *   同じ保存の書き方を画面ごとに持つと、片方だけ直して食い違う。
 *   本人の申告6項目は [[ProfileExtras]] が持つ＝**分けてあるのは触る列が違うから**。
 *
 * 【決まりは ProfileExtras と同じ＝「送られてきた欄だけ直す」】
 * ⚠ 画面によって出している項目が違う。送られてこなかった欄まで空で上書きすると、
 *   **別の画面で入れた内容が消える**。とくに `/profile` からマイページの欄に移したとき、
 *   氏名や身長が空になるのがいちばん怖い。
 */
final class ProfileBasics
{
    /** そのまま入れる欄（空欄は null）。 */
    private const TEXTS = [
        'name_kana', 'email', 'chatwork_id', 'office',
        // 入社年月日（2026-09-01 baba要望）。これまで初回の初期設定でしか入れられず、
        // ⚠ 間違えても本人が直せなかった（「生年月日を入れてしまった」方が出た）。
        //   名簿の並び順と区分（新人／中堅／ベテラン）の計算に使う。
        'hire_date',
        'height', 'shoe_size', 'shirt_size', 'prefecture', 'nearest_station',
    ];

    /**
     * 入力チェックの決まり。⚠ 画面ごとに書き写さず、これを足して使う。
     * ⚠ 氏名は `sometimes` を付ける＝**送られてきたときだけ必須**。
     *   付けないと、氏名を出していない画面（マイページの他の欄）から保存できなくなる。
     */
    public const RULES = [
        'name' => ['sometimes', 'required', 'string', 'max:255'],
        'name_kana' => ['nullable', 'string', 'max:255'],
        'email' => ['nullable', 'email'],
        // チャットワークID＝リマインドの宛先。数字のみ（桁が多いので文字列で持つ）。
        'chatwork_id' => ['nullable', 'string', 'max:32', 'regex:/^[0-9]+$/'],
        'office' => ['nullable', 'string', 'max:50'],
        'hire_date' => ['nullable', 'date'],
        'height' => ['nullable', 'string', 'max:20'],
        'shoe_size' => ['nullable', 'string', 'max:20'],
        'shirt_size' => ['nullable', 'string', 'max:20'],
        'prefecture' => ['nullable', 'string', 'max:20'],
        'nearest_station' => ['nullable', 'string', 'max:100'],
        'department' => ['nullable', 'string', 'max:50'],
        'departments' => ['nullable', 'array'],
        'departments.*' => ['string', 'max:50'],
    ];

    /** エラー文に出す日本語名。 */
    public const LABELS = [
        'name' => '氏名',
        'name_kana' => 'ふりがな',
        'email' => 'メールアドレス',
        'chatwork_id' => 'チャットワークID',
        'office' => '事務所',
        'hire_date' => '入社年月日',
        'height' => '身長',
        'shoe_size' => '靴（足袋）のサイズ',
        'shirt_size' => '服（衣装）のサイズ',
        'prefecture' => '都道府県',
        'nearest_station' => '最寄り駅',
        'department' => '主な所属',
        'departments' => '兼務している所属',
    ];

    /** エラー文の言い換え。 */
    public const MESSAGES = [
        'chatwork_id.regex' => 'チャットワークIDは数字だけで入れてください。',
    ];

    /**
     * 画面から来た値を本人に入れる（保存はしない＝呼んだ側が save() する）。
     *
     * @param  array<string, mixed>  $input  リクエストの中身（$request->all() など）
     */
    public static function apply(Person $user, array $input): void
    {
        // 氏名だけは空にできない（空で送られてきたら、その欄は触らない）。
        if (array_key_exists('name', $input) && trim((string) $input['name']) !== '') {
            $user->name = trim((string) $input['name']);
        }

        foreach (self::TEXTS as $column) {
            if (array_key_exists($column, $input)) {
                // 空欄は null で入れる＝「未入力」と「空文字」を見分けなくて済むように。
                $user->{$column} = trim((string) $input[$column]) ?: null;
            }
        }

        // 所属は社員だけ。⚠ 兼務は「選んだものだけ送られる」ので、
        //   `departments_sent` の印が来ていれば、1つも無くても「全部外した」として直す。
        if ($user->role !== 'employee') {
            return;
        }

        $touchMain = array_key_exists('department', $input);
        $touchSub = array_key_exists('departments_sent', $input) || array_key_exists('departments', $input);

        if ($touchMain) {
            $user->department = trim((string) $input['department']) ?: null;
        }

        if ($touchMain || $touchSub) {
            // ⚠ 主な所属は必ず含める（兼務のチェックを外していても入れる）。正本＝Departments::normalize。
            $user->departments = Departments::normalize(
                $user->department,
                (array) ($input['departments'] ?? ($touchSub ? [] : $user->departmentList()))
            );
        }
    }
}
