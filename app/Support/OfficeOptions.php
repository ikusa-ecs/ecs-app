<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 拠点ごとの「選べるもの」（集合形式・音響機材・移動車両・運営場所）の正本。
 *
 * なぜ必要か（2026-08-21 baba）：
 *   9月から東京・名古屋の2拠点でECSを使い始める。集合場所の「大住」「広宣」や
 *   「IKUSAカー」のように、東京にしか無いものが名古屋の案件登録でも出てしまう。
 *   拠点ごとに出す選択肢を変えられるようにする。
 *
 * 作り：
 *   ・既定（DEFAULTS）＝これまで画面に直書きしていた一覧。**設定が無い拠点はこれが出る**
 *     ＝新しい拠点を作っても、まず東京と同じ内容で使い始められる。
 *   ・マスタ管理（/masters の「拠点ごとの選択肢」）で拠点ごとに書き換えると、そちらが優先される。
 *     保存先は settings テーブル（キー＝office_options.<拠点名>.<種類>、1行1項目のテキスト）。
 *
 * ⚠ 画面（project_form.blade.php）に選択肢を直書きしないこと。増やすときはここか、マスタ管理から。
 */
class OfficeOptions
{
    /** 種類のキー => 画面に出す名前。マスタ管理の見出しにも使う。 */
    public const KINDS = [
        'assembly_type'   => '集合形式',
        'audio_equipment' => '音響機材',
        'transport'       => '移動・車両',
        'operation_place' => '運営場所',
    ];

    /**
     * 既定の一覧（＝2026-08-21 まで画面に直書きしていた内容）。
     * 設定していない拠点は、これがそのまま出る。
     */
    public const DEFAULTS = [
        'assembly_type' => [
            '会場現地', '大住', '広宣', '事務所+会場現地', '駅', '空港', 'その他（備考に記載）',
        ],
        'audio_equipment' => [
            '会場音響', 'クラシックプロ大', 'クラシックプロ中', 'クラシックプロ小',
            'CUBE', 'SANWA', 'TOA', '不要',
        ],
        'transport' => [
            'IKUSAカー', 'IKUSAカー2台', 'IKUSAカー3台', '電車', 'レンタカー',
            'IKUSAカー+レンタカー', '電車+IKUSAカー', '電車+レンタカー', '飛行機', '飛行機+レンタカー',
        ],
        // 運営場所は、この一覧に「○○依頼」（他拠点へ依頼）が自動で足される。
        'operation_place' => [
            '現地', '配信室(広宣)', '配信室横(広宣)', '芝生(広宣)',
        ],
    ];

    /** 設定の保存キー。 */
    private static function key(string $office, string $kind): string
    {
        return 'office_options.' . $office . '.' . $kind;
    }

    /** その拠点・その種類の一覧。マスタ未設定なら既定を返す。 */
    public static function get(string $office, string $kind): array
    {
        $saved = trim((string) Setting::get(self::key($office, $kind), ''));

        if ($saved === '') {
            return self::DEFAULTS[$kind] ?? [];
        }

        return self::fromText($saved);
    }

    /** マスタ管理からの保存（1行1項目のテキスト）。既定と同じ内容なら設定を消す＝既定に戻す。 */
    public static function put(string $office, string $kind, string $text): void
    {
        $list = self::fromText($text);

        if ($list === (self::DEFAULTS[$kind] ?? [])) {
            Setting::put(self::key($office, $kind), '');   // 既定と同じ＝設定なしに戻す
            return;
        }

        Setting::put(self::key($office, $kind), implode("\n", $list));
    }

    /** 編集画面に出すテキスト（1行1項目）。 */
    public static function text(string $office, string $kind): string
    {
        return implode("\n", self::get($office, $kind));
    }

    /**
     * 運営場所だけは「○○依頼」（自分以外の拠点へ依頼）を自動で足す。
     * 拠点マスタに拠点を足せば、ここにも自動で出る。
     */
    public static function operationPlaces(string $office, array $allOffices): array
    {
        $base = self::get($office, 'operation_place');

        foreach ($allOffices as $name) {
            if ($name !== $office) {
                $base[] = $name . '依頼';
            }
        }

        return array_values(array_unique($base));
    }

    /**
     * 画面（案件登録）に渡す一覧。拠点名 => 種類 => 選択肢の配列。
     * 拠点を選び直したときに、その場でプルダウンの中身を入れ替えるために全拠点ぶん渡す。
     */
    public static function mapForAll(array $offices): array
    {
        $map = [];

        foreach ($offices as $office) {
            foreach (array_keys(self::KINDS) as $kind) {
                $map[$office][$kind] = $kind === 'operation_place'
                    ? self::operationPlaces($office, $offices)
                    : self::get($office, $kind);
            }
        }

        return $map;
    }

    /** テキスト（1行1項目）を配列に。空行・前後の空白・重複は落とす。 */
    private static function fromText(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_unique(array_filter(
            array_map(fn ($l) => trim($l), $lines),
            fn ($l) => $l !== ''
        )));
    }
}
