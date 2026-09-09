<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Support\Facades\Auth;

/**
 * 画面の色（テーマ）の正本（2026-09-09 baba要望）。
 *
 * 【babaの言葉】「カラーリングを個人によって変更することって可能？
 *   今白よりだけど、画面黒ベースのほうが好きな人もいるし、
 *   会社のカラー的にはオレンジとか白とか（ikusa.co.jp）こんな感じ」
 *
 * 【いま出しているもの】
 *   ''（空）  … いままでの色（くすみベージュ＋テラコッタ）＝既定
 *   'orange' … 会社カラー（オレンジ×白）
 *
 * 【まだ無いもの】黒ベース（ダークモード）。
 *   ⚠ 各画面が**直に書いている色が約1,950か所**あり、そのほとんどが
 *     「白い背景に濃い文字」を前提にしている。いま黒にすると、そこだけ白いまま残って読めない。
 *     ⇒ 画面ごとに色を変数へ置き換えてから足す（段階的にやる・2026-09-09 baba了承）。
 *
 * 【色そのものはどこにあるか】`public/ecs/style.css` の `html[data-theme="…"]` の並び。
 *   ⚠ **CSSに書く。PHPやBladeに色を書き写さない。** ここが2か所に分かれると必ず食い違う。
 *   この PHP が持っているのは「どのテーマがあるか」と「誰が何を選んでいるか」だけ。
 */
final class Themes
{
    /** 何も選んでいない人の色（空＝いままでの色）。 */
    public const DEFAULT = '';

    /**
     * 選べるテーマ。[値 => 画面に出す名前]。
     * ⚠ 足すときは、ここと style.css の `html[data-theme="…"]` の**両方**に足す。
     */
    public const OPTIONS = [
        '' => 'いまの色（やわらかい・かわいい感じ）',
        'orange' => '会社カラー（スタイリッシュ）',
    ];

    /**
     * **見本だけ**のテーマ（まだ選んで保存はできない）。
     *
     * ⚠ 黒ベースは、いまダッシュボード（/dashboard）だけ色を整えてある。
     *   保存できるようにすると、他の画面が白いまま残った状態で固定されてしまうので、
     *   「その画面をいちど黒で見る」だけにしている（2026-09-09 baba「黒もどんな感じか1画面見本で見たい」）。
     * ⚠ 画面ごとに色を整え終えたら、ここから OPTIONS へ移す。
     */
    public const PREVIEW_ONLY = [
        'dark' => '黒ベース（シンプル・見本）',
    ];

    /** 見本を見せる画面（この1枚だけ黒に整えてある）。 */
    public const PREVIEW_PATH = '/dashboard';

    /**
     * 説明（選ぶ画面に小さく出す一言）。
     * ⚠ 言い方は baba のイメージそのまま（2026-09-09）＝
     *   「黒背景はシンプル、自社カラーはスタイリッシュ、今までの背景は女性向けのかわいい感じ」。
     *   ここを言い換えると、選ぶ人が受け取る印象が設計の意図とずれる。
     */
    public const NOTES = [
        '' => 'これまでどおりのベージュ。やわらかく、あたたかい感じの画面です。',
        'orange' => 'IKUSA のコーポレートサイトの雰囲気。白地×グレー×オレンジで、きりっとした見た目です。',
        'dark' => '黒ベース。飾りを落としたシンプルな見た目で、目が疲れにくいです（いまは見本）。',
    ];

    /**
     * 知らない値・空は既定に寄せる（URLや古いデータで変な値が入っても崩れないように）。
     * ⚠ **保存できるのは OPTIONS だけ。** 見本（PREVIEW_ONLY）はここを通らない＝DBに入らない。
     */
    public static function normalize(?string $value): string
    {
        $v = trim((string) $value);

        return array_key_exists($v, self::OPTIONS) ? $v : self::DEFAULT;
    }

    /** その人のテーマ。 */
    public static function of(?Person $person): string
    {
        return self::normalize($person?->theme);
    }

    /**
     * いまログインしている人のテーマ（画面の <html data-theme="…"> に出す）。
     * ログインしていない画面（ログイン・パスワード再設定など）は既定のまま。
     *
     * ⚠ URLに `?theme=…` が付いているときは、**その画面を見ている間だけ**それで表示する（見本用）。
     *   保存はしない＝リンクを閉じればいつもの色に戻る。他の人の画面にも影響しない。
     */
    public static function current(): string
    {
        $preview = self::previewFromUrl();
        if ($preview !== null) {
            return $preview;
        }

        $user = Auth::user();

        return self::of($user instanceof Person ? $user : null);
    }

    /**
     * URLの `?theme=…` で指定された「見本の色」。指定が無い・知らない値なら null。
     * ⚠ ここで受け付けた値は**表示だけ**に使う（保存は ThemeController → normalize の道だけ）。
     */
    public static function previewFromUrl(): ?string
    {
        $v = trim((string) request()->query('theme', ''));
        if ($v === '') {
            return null;
        }

        return array_key_exists($v, self::OPTIONS) || array_key_exists($v, self::PREVIEW_ONLY) ? $v : null;
    }

    /** いま見本として表示しているか（画面の上に「見本です」の帯を出すのに使う）。 */
    public static function previewLabel(): ?string
    {
        $v = self::previewFromUrl();
        if ($v === null) {
            return null;
        }

        return self::PREVIEW_ONLY[$v] ?? (self::OPTIONS[$v] ?? null);
    }
}
