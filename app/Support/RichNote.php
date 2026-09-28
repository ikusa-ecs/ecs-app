<?php

namespace App\Support;

/**
 * 案件の備考（projects.note）に「赤」と「太字」を付ける（2026-09-28 baba要望）。
 *
 * 【しくみ】備考はいままでどおりふつうの文字で保存し、その中に印を書く。
 *   **文字**        … 太字
 *   [赤]文字[/赤]   … 赤い文字
 *   表に列を足さずに済み、印に対応していない画面（案件登録の入力欄・CSV出力）でも
 *   印がそのまま見えるだけで、文字は消えない。
 *
 * ⚠ HTMLをそのまま保存・表示しない。先に全部の文字を無害化（エスケープ）してから、
 *   決まった2つの印だけを色・太字に置き換える＝備考に何を書かれても画面は壊れない。
 * ⚠ 画面のJSで描く画面は window.ecsRichNote（partials/rich_note）を使う。置き換えの決まりは
 *   ここと同じにしておくこと（テスト RichNoteTest が両方の印の名前を見張っている）。
 */
class RichNote
{
    public const BOLD = '**';

    public const RED_OPEN = '[赤]';

    public const RED_CLOSE = '[/赤]';

    /** 画面に出すHTML（改行は <br>）。 */
    public static function html(?string $text): string
    {
        $s = e((string) $text);

        $s = preg_replace('/\[赤\](.+?)\[\/赤\]/us', '<span class="rn-red">$1</span>', $s);
        $s = preg_replace('/\*\*(.+?)\*\*/us', '<b>$1</b>', $s);

        // ⚠ nl2br は改行を残したまま <br> を足す＝white-space:pre-wrap の欄で行が2倍に空く。改行は <br> に置き換える。
        return preg_replace('/\r?\n/', '<br>', $s);
    }

    /** 印を外した文字（検索・LINEなど、色を出せないところ用）。 */
    public static function plain(?string $text): string
    {
        $s = (string) $text;
        $s = preg_replace('/\[赤\](.+?)\[\/赤\]/us', '$1', $s);

        return preg_replace('/\*\*(.+?)\*\*/us', '$1', $s);
    }
}
