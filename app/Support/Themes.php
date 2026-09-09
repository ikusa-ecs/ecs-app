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
 * 【いま出しているもの】3つ。baba のイメージそのままの言い方にしている。
 *   ''（空）  … いままでの色（やわらかい・かわいい感じ）＝既定
 *   'orange' … 会社カラー（スタイリッシュ）
 *   'dark'   … 黒ベース（シンプル）
 *
 * 【黒ベースの経緯】各画面が**直に書いている色が約1,950か所**あり、そのほとんどが
 *   「白い背景に濃い文字」を前提だった。2026-09-09 に全画面へ
 *   `html[data-theme="dark"]` の読み替えを入れて（baba「全画面進めてOK」）、選べるようにした。
 *   ⚠ **画面を新しく作るときは、面の色を var(--panel)・文字を var(--ink) で書く。**
 *     白や茶色を直に書くと、その画面だけ黒ベースで読めなくなる。
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
        // 2026-09-09 baba「デザイナーと相談して見やすいように全画面進めてOK」
        // ＝全画面を黒でも読めるように直したので、見本から「選べる色」に格上げした。
        'dark' => '黒ベース（シンプル）',
    ];

    /**
     * **見本だけ**のテーマ（まだ選んで保存はできないもの）。いまは空。
     *
     * 使い方＝画面ごとの色を整え終わっていないテーマをここに置くと、
     * `?theme=…` を付けたときだけ見られる（保存はできない）ので、
     * 「全画面が崩れたまま固定される」ことなく見た目を確かめられる。
     * ⚠ 黒ベースは 2026-09-09 に全画面を整えたので OPTIONS へ移した（ここは空になった）。
     */
    public const PREVIEW_ONLY = [];

    /** 見本を見せるときに開く画面（`?theme=…` の行き先）。 */
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
        'dark' => '黒ベース。飾りを落としたシンプルな見た目で、目が疲れにくいです。',
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
