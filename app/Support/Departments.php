<?php

namespace App\Support;

/**
 * 所属（部署）の正本（2026-08-24 baba）。
 *
 * これまで画面・集計・色分けの4か所に「イベプラ／セールス／クリエイティブ」の3つだけを
 * 直書きしていたため、それ以外の所属の人が「イベプラ」と誤表示されていた
 * （対応表が既定値 'plan' に倒れていた）。
 *
 * 【考え方】
 *  ・実際の所属は11種類ある（ALL）。名簿には本当の所属名をそのまま保存する（情報を捨てない）。
 *  ・ただし色分け・絞り込み・集計は4つにまとめる（GROUPS）＝
 *    イベプラ／セールス／クリエイティブ／その他。色を増やしても読みにくいだけ、というbaba判断。
 *  ・所属が空の人は「未設定」。その他とは分けて出す（誰が未入力かすぐ分かるように）。
 *
 * ⚠ 所属が増えたときに直すのはこのファイルだけ。画面・集計・色はここを読む。
 *   JSやBladeに部署名・色を書き足さないこと（食い違いの原因になる）。
 */
final class Departments
{
    /**
     * 実際の所属の一覧（入力プルダウンに出す順）。
     * 前の3つ（イベプラ／セールス／クリエイティブ）は今までの順を保つ。
     */
    public const ALL = [
        'イベプラ',
        'セールス',
        'クリエイティブ',
        // 2026-08-25 baba要望で追加。色分け・集計は「その他」にまとめる（MAIN に入れていないため）。
        // 単独で色を付けて数えたくなったら MAIN と GROUP_CODES / COLORS に足す。
        'アサイン',
        '経営管理',
        'マーケティング',
        'イベプロ',
        'プロダクション',
        'ビジパ',
        'ARENA',
        'あそ研',
    ];

    /** 単独で色分け・集計する所属（これ以外は「その他」にまとめる）。 */
    public const MAIN = ['イベプラ', 'セールス', 'クリエイティブ'];

    /** まとめ先の名前。 */
    public const OTHER = 'その他';

    /** 色分け・絞り込み・集計の単位（この4つ＋未設定）。 */
    public const GROUPS = ['イベプラ', 'セールス', 'クリエイティブ', self::OTHER];

    /** D決め画面で既定表示にする所属（ここに属する人がDに立つ）。 */
    public const PLANNER = 'イベプラ';

    /** 所属が空のときの表示。 */
    public const UNSET_LABEL = '未設定';

    /** グループ名 → CSSのクラス用コード。前からある3つはコードも変えない（見た目を保つため）。 */
    private const GROUP_CODES = [
        'イベプラ' => 'plan',
        'セールス' => 'sales',
        'クリエイティブ' => 'creative',
        self::OTHER => 'other',
    ];

    /** コード → [バッジの背景, 文字色]。前からある3つは今までの色をそのまま使う。 */
    private const COLORS = [
        'plan' => ['#e0f2fe', '#0369a1'],   // 水色（イベプラ）
        'sales' => ['#e7f6ec', '#15803d'],   // 緑（セールス）
        'creative' => ['#f3e8ff', '#7c3aed'],   // 紫（クリエイティブ）
        'other' => ['#f0ebe3', '#6e5b49'],   // ベージュ（その他）
        'none' => ['#faf6ee', '#a3968a'],   // 薄灰（未設定）
    ];

    /**
     * 実際の所属名 → 色分け・集計に使うグループ名。
     * イベプラ／セールス／クリエイティブはそのまま。それ以外は「その他」。空は空。
     */
    public static function group(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        return in_array($name, self::MAIN, true) ? $name : self::OTHER;
    }

    /** 所属名 → CSSコード（plan/sales/creative/other、空は none）。 */
    public static function code(?string $name): string
    {
        $group = self::group($name);

        return $group === '' ? 'none' : self::GROUP_CODES[$group];
    }

    /**
     * 名簿のバッジに出す文字。
     * 本当の所属名をそのまま出す（「経営管理」なら「経営管理」）。空なら「未設定」。
     * ＝色はその他でまとめるが、誰がどこの所属かは読めるようにしておく。
     */
    public static function label(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? self::UNSET_LABEL : $name;
    }

    /**
     * 保存する「兼務を含めた所属の配列」を作る（主な所属を先頭に・重複と空を落とす）。
     *
     * なぜ1か所にまとめるか＝マイプロフィール／アカウント発行／名簿CSV取込の3つの入口が
     * それぞれ違う整え方をすると、集計（主な所属で数える）と表示（兼務も出す）が食い違う。
     *
     * ・主な所属は必ず含める（チェックを外して保存されても入れる）。
     * ・一覧に無い名前は捨てる（タイポや古い部署名が混ざらないように）。
     * ・1つも残らなければ null（＝未設定）。
     */
    public static function normalize(?string $main, array $others = []): ?array
    {
        $list = [];
        $main = trim((string) $main);
        if ($main !== '') {
            $list[] = $main;
        }
        foreach ($others as $d) {
            $d = trim((string) $d);
            if ($d !== '') {
                $list[] = $d;
            }
        }

        $list = array_values(array_unique(array_filter($list, [self::class, 'isKnown'])));

        return $list ?: null;
    }

    /** その名前が一覧にあるか（CSV取込の警告などに使う）。 */
    public static function isKnown(?string $name): bool
    {
        return in_array(trim((string) $name), self::ALL, true);
    }

    /** 絞り込みプルダウン用：[コード => グループ名]（イベプラ／セールス／クリエイティブ／その他）。 */
    public static function groupOptions(): array
    {
        $out = [];
        foreach (self::GROUPS as $g) {
            $out[self::GROUP_CODES[$g]] = $g;
        }

        return $out;
    }

    /**
     * バッジの色をCSSにする（画面の <style> にそのまま流し込む）。
     * 色をBladeやJSに直書きしないため、ここで作って渡す。
     */
    public static function badgeCss(string $selector = '.dept'): string
    {
        $out = [];
        foreach (self::COLORS as $code => [$bg, $fg]) {
            $out[] = "{$selector}.{$code} { background: {$bg}; color: {$fg}; }";
        }

        return implode("\n    ", $out);
    }

    /** 名前の文字色だけを変えたい画面（D決め・集計）向けのCSS。 */
    public static function nameColorCss(string $prefix = '.dep'): string
    {
        $out = [];
        foreach (self::COLORS as $code => [, $fg]) {
            $out[] = "{$prefix}-{$code} .e-nm, {$prefix}-{$code} { color: {$fg}; }";
        }

        return implode("\n    ", $out);
    }
}
