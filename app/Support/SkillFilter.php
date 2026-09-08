<?php

namespace App\Support;

use App\Models\Person;

/**
 * 名簿を「スキルで絞る」ときの唯一の正（single source of truth）。2026-09-08 baba要望。
 *
 * 【babaの言葉】「スタッフと社員側でスキルを選択してピックアップできるようにしたい。
 *   例）英語ができる人を見たい」
 *
 * 【なぜ要るか】
 * これまで英語・運転などは**名簿の「詳細」を開かないと見えなかった**＝
 * 英語ができる人を探すには 45人ぶん開いて回るしかなかった。
 * 絞り込みを付ける画面が**2つ**（スタッフ名簿 /staff・社員名簿 /employees）あるので、
 * 判定と選択肢を画面ごとに書き写すと**片方だけ直して食い違う**（この repo で何度も起こしている事故）。
 * ⇒ 「どんな項目があるか」と「その人が持っているか」は、**このファイルだけ**で決める。
 *
 * 【画面（JS）との受け渡し】
 * ・選択肢＝`options()` をコントローラが渡す → 画面はプルダウンを組み立てるだけ。
 * ・その人が持っているスキル＝`keysFor()` を「印（key）の配列」で渡す
 *   → 画面の絞り込みは `p.skills.includes(選んだkey)` の1行で済む＝**判定をJSに書かない**。
 * ・一覧の行に出すバッジ＝`badgesFor()`。
 *
 * ⚠ 英語・運転は people の列のまま（スキルマスタへ移さない）。
 *   英語は「片言／日常会話／ビジネス会話可能」の**3段階**で本人が入れている。
 *   マスタ（持っている・いないだけ）へ移すとレベルが消え、
 *   いちばん知りたい「ビジネス会話まで任せられる人」が探せなくなる＝機能の後退になる。
 *   選択肢の文字そのものの正本は App\Support\ProfileOptions。
 */
final class SkillFilter
{
    /**
     * 「この段以上」で絞るときの境目。⚠ 文字の正本は ProfileOptions（ここは参照するだけ）。
     * 一覧から消えたら絞り込みは空になる＝テストで見張っている。
     */
    private const ENGLISH_DAILY = '日常会話レベル';

    private const ENGLISH_BIZ = 'ビジネス会話可能レベル';

    private const DRIVING_HIACE = 'ハイエースも普通サイズも運転可能';

    /**
     * 絞り込みに並べる項目。
     *   key       … 絞り込みの値（画面から送られてくる印。DBには入らない）
     *   label     … プルダウンに出す文字
     *   badge     … 一覧の行に出すバッジの文字（短くする）
     *   staffOnly … スタッフ名簿にだけ出す（社員には入力する画面が無い項目）
     *
     * ⚠ 「英語：日常会話レベル以上」のように**下限で絞る**項目がある＝
     *   ビジネス会話可能の人は日常会話以上にも含まれる（重なるのは意図どおり）。
     *   「英語ができる人」を探すとき、まず広く見て、狭めたいときに下の段を選べるようにするため。
     */
    private const ITEMS = [
        [
            'key' => 'english_daily',
            'label' => '英語：日常会話レベル以上',
            'badge' => '英語：日常会話',
            'staffOnly' => false,
        ],
        [
            'key' => 'english_biz',
            'label' => '英語：ビジネス会話可能',
            'badge' => '英語：ビジネス',
            'staffOnly' => false,
        ],
        [
            'key' => 'drive',
            'label' => '運転できる（普通サイズ以上）',
            'badge' => '運転',
            'staffOnly' => false,
        ],
        [
            'key' => 'drive_hiace',
            'label' => '運転：ハイエースも可',
            'badge' => '運転：ハイエース可',
            'staffOnly' => false,
        ],
        [
            'key' => 'kigurumi',
            'label' => '着ぐるみOK',
            'badge' => '着ぐるみ',
            'staffOnly' => true,
        ],
        [
            'key' => 'stay_over',
            'label' => '前泊・後泊OK',
            'badge' => '前泊OK',
            'staffOnly' => true,
        ],
        [
            'key' => 'mc_passed',
            'label' => 'MC審査合格',
            'badge' => 'MC合格',
            'staffOnly' => true,
        ],
    ];

    /**
     * プルダウンに並べる選択肢（先頭の「スキル：すべて」は画面側で足す）。
     *
     * @param  bool  $includeStaffOnly  スタッフ名簿か（true＝着ぐるみ・前泊・MC審査も並べる）
     * @return array<int, array{key: string, label: string}>
     */
    public static function options(bool $includeStaffOnly): array
    {
        $out = [];
        foreach (self::ITEMS as $item) {
            // ⚠ 社員名簿には「着ぐるみ・前泊・MC審査」を出さない。
            //   社員が入力する画面が無い＝必ず0名になり「壊れている」と誤解されるため。
            if ($item['staffOnly'] && ! $includeStaffOnly) {
                continue;
            }
            $out[] = ['key' => $item['key'], 'label' => $item['label']];
        }

        return $out;
    }

    /**
     * その人が持っているスキルの印（key）。画面の絞り込みはこれを見るだけ。
     *
     * @return array<int, string>
     */
    public static function keysFor(Person $person): array
    {
        $has = self::evaluate($person);

        return array_values(array_filter(
            array_column(self::ITEMS, 'key'),
            fn (string $key) => $has[$key] ?? false
        ));
    }

    /**
     * 一覧の行に出すバッジの文字。
     *
     * ⚠ 英語・運転は「重なる段」を持っているので、**いちばん上の段だけ**出す
     *   （ビジネス会話可の人に「日常会話」「ビジネス」の2つを並べると読みにくい）。
     *
     * @return array<int, string>
     */
    public static function badgesFor(Person $person): array
    {
        $has = self::evaluate($person);
        // 重なる段は下の段を消す＝上の段（より高い方）を残す。
        $hidden = [];
        if ($has['english_biz']) {
            $hidden[] = 'english_daily';
        }
        if ($has['drive_hiace']) {
            $hidden[] = 'drive';
        }

        $out = [];
        foreach (self::ITEMS as $item) {
            if (($has[$item['key']] ?? false) && ! in_array($item['key'], $hidden, true)) {
                $out[] = $item['badge'];
            }
        }

        return $out;
    }

    /**
     * 判定の本体＝key => 持っているか。**判定はここ1か所だけ**に書く。
     *
     * ⚠ 英語の「片言レベル」は絞り込みにも印にも出さない。
     *   探す目的は「英語の案件を任せられる人を見つけること」なので、
     *   片言の人を混ぜると探した意味がなくなる（本人の入力は詳細パネルにそのまま出ている）。
     *
     * @return array<string, bool>
     */
    private static function evaluate(Person $person): array
    {
        $english = trim((string) $person->english_level);
        $driving = trim((string) $person->driving_level);

        return [
            // 英語＝「その段以上か」で見る。段の並びの正本は ProfileOptions::ENGLISH（下から上へ）。
            'english_daily' => self::atLeast($english, ProfileOptions::ENGLISH, self::ENGLISH_DAILY),
            'english_biz' => self::atLeast($english, ProfileOptions::ENGLISH, self::ENGLISH_BIZ),
            // 運転＝何か選んでいれば「普通サイズ以上は運転できる」（選択肢が2つとも運転可のため）。
            'drive' => $driving !== '' && in_array($driving, ProfileOptions::DRIVING, true),
            'drive_hiace' => self::atLeast($driving, ProfileOptions::DRIVING, self::DRIVING_HIACE),
            'kigurumi' => (bool) $person->can_kigurumi,
            'stay_over' => (bool) $person->can_stay_over,
            'mc_passed' => (bool) $person->mc_audition_passed,
        ];
    }

    /**
     * 「$value は $threshold 以上の段か」。$list の**並び順**を段の高さとして使う。
     *
     * ⚠ $threshold が $list から消えていたら **false（誰にも当たらない）** を返す。
     *   選択肢の言い方を変えると絞り込みが黙って空になるので、
     *   tests/Feature/SkillFilterTest.php が「文字がまだあるか」を見張っている。
     */
    private static function atLeast(string $value, array $list, string $threshold): bool
    {
        $rank = array_search($value, $list, true);
        $need = array_search($threshold, $list, true);

        return $rank !== false && $need !== false && $rank >= $need;
    }
}
