<?php

namespace App\Support;

use App\Models\Person;

/**
 * 本人が申告するプロフィール6項目の「保存のしかた」の正本（2026-08-31）。
 *
 * 対象＝運転／英語／その他話せる言語／チャレンジしたいポジション／
 *       日常で使っているオンラインツール（＋その他）／その他備考。
 * **社員もスタッフも同じ列**に入る（people の同じ6＋1列）。
 *
 * 【なぜ要るか】
 * ⚠ この6項目を入れられる画面が**4つ**ある。
 *    ① マイプロフィール `/profile`
 *    ② スタッフ画面の設定タブ
 *    ③ **マイページのカード（2026-08-31 追加。ここが分かりにくいと言われた入口）**
 *    ④（将来）名簿から管理者が直すとき
 *   入力チェックと保存の書き方を画面ごとに書き写すと、**片方だけ直して食い違う**。
 *   実際に実施形態で同じ事故を起こしている（[[ProjectFormats]] と同じ考え方）。
 *   選択肢そのものの正本は App\Support\ProfileOptions、**保存のしかたはここ**。
 *
 * 【決まりはひとつ＝「送られてきた欄だけ直す」】
 * ⚠ 画面によって、6項目のうち一部しか出していないことがある。
 *   送られてこなかった欄まで空で上書きすると、**別の画面で入れた内容が消える**。
 *   ・ふつうの欄 … その名前が送られてきていれば直す
 *   ・チェックの欄 … `challenge_positions_sent` のような**印**が来ていれば直す
 *     （チェックは「選んだものだけ送られる」ので、全部外すと欄ごと届かない＝
 *      印が無いと**一度入れたら二度と消せない**画面になる）
 */
final class ProfileExtras
{
    /** 1つだけ選ぶ欄（プルダウン）＝列名 => 選択肢の一覧。 */
    private const CHOICES = [
        'driving_level' => ProfileOptions::DRIVING,
        'english_level' => ProfileOptions::ENGLISH,
    ];

    /** 複数チェックの欄＝列名 => 選択肢の一覧。 */
    private const CHECKS = [
        'challenge_positions' => ProfileOptions::CHALLENGE_POSITIONS,
        'online_tools' => ProfileOptions::ONLINE_TOOLS,
    ];

    /** 自由に書く欄。 */
    private const TEXTS = ['other_languages', 'online_tools_other', 'profile_note'];

    /**
     * 入力チェックの決まり。⚠ 画面ごとに書き写さず、これを足して使う。
     * 選択肢に無い値は保存のときに落とすので、ここでは「形」だけ見る。
     */
    public const RULES = [
        'driving_level' => ['nullable', 'string', 'max:100'],
        'english_level' => ['nullable', 'string', 'max:100'],
        'other_languages' => ['nullable', 'string', 'max:255'],
        'online_tools_other' => ['nullable', 'string', 'max:255'],
        'profile_note' => ['nullable', 'string', 'max:2000'],
        'challenge_positions' => ['nullable', 'array'],
        'challenge_positions.*' => ['string', 'max:50'],
        'online_tools' => ['nullable', 'array'],
        'online_tools.*' => ['string', 'max:50'],
    ];

    /** エラー文に出す日本語名。 */
    public const LABELS = [
        'driving_level' => '運転',
        'english_level' => '英語',
        'other_languages' => 'その他話せる言語',
        'online_tools_other' => 'その他のオンラインツール',
        'profile_note' => 'その他備考',
        'challenge_positions' => 'チャレンジしたいポジション',
        'online_tools' => '日常で使っているオンラインツール',
    ];

    /**
     * 画面から来た値を本人に入れる（保存はしない＝呼んだ側が save() する）。
     *
     * @param  array<string, mixed>  $input  リクエストの中身（$request->all() など）
     */
    public static function apply(Person $user, array $input): void
    {
        foreach (self::CHOICES as $column => $allowed) {
            if (array_key_exists($column, $input)) {
                $user->{$column} = ProfileOptions::normalizeChoice($input[$column], $allowed);
            }
        }

        foreach (self::TEXTS as $column) {
            if (array_key_exists($column, $input)) {
                // 空欄は null で入れる＝「未入力」と「空文字」を見分けなくて済むように。
                $user->{$column} = trim((string) $input[$column]) ?: null;
            }
        }

        foreach (self::CHECKS as $column => $allowed) {
            // ⚠ 印（_sent）が来ていれば、チェックが1つも無くても「全部外した」として直す。
            if (array_key_exists($column.'_sent', $input) || array_key_exists($column, $input)) {
                $user->{$column} = ProfileOptions::normalizeChecks($input[$column] ?? [], $allowed);
            }
        }
    }

    /**
     * 画面に出すための「いまの中身」。未記入は空文字／空配列で返す。
     *
     * @return array<string, mixed>
     */
    public static function of(Person $user): array
    {
        return [
            'driving_level' => (string) ($user->driving_level ?? ''),
            'english_level' => (string) ($user->english_level ?? ''),
            'other_languages' => (string) ($user->other_languages ?? ''),
            'challenge_positions' => (array) ($user->challenge_positions ?? []),
            'online_tools' => (array) ($user->online_tools ?? []),
            'online_tools_other' => (string) ($user->online_tools_other ?? ''),
            'profile_note' => (string) ($user->profile_note ?? ''),
        ];
    }

    /**
     * まだ1つも入れていないか（マイページで「未記入です」と出すかの判定）。
     * ⚠ 「全部入っているか」ではない＝一部でも入れた人をせかさない。
     */
    public static function isEmpty(Person $user): bool
    {
        foreach (self::of($user) as $value) {
            if ($value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }
}
