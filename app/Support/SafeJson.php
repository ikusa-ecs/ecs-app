<?php

namespace App\Support;

/**
 * 画面へデータを渡すときの JSON の唯一の正（2026-08-31）。
 *
 * 【なぜ要るか＝本番でスタッフ画面が丸ごと止まった】
 * Blade の `@json(...)` は中で json_encode を呼ぶが、
 * **文字コードが壊れた文字が1つでも混ざっていると json_encode は「失敗」を返す**。
 * Blade はその失敗（false）をそのまま出すので、出来上がるのは
 *      window.ECS_RECRUIT_JOBS = ;
 * という**文法として成り立たない行**になる。すると：
 *   ・その画面の JavaScript が**丸ごと読み込みに失敗する**
 *   ・案件一覧が空になる／タブもボタンも一切押せなくなる
 *   ・画面は普通に出るので、目で見ても気づけない
 * ＝ 2026-08-31 に本番のスタッフ画面で実際に起きた形。
 *
 * 文字化けは、CSVの取り込み（Excelの文字コード違い）やコピペで簡単に入ってくる。
 * **たった1件の壊れたデータで全員の画面が止まる**のが最大の問題なので、
 * 壊れた文字は「�」に置き換えてでも**画面を動かし続ける**のが正しい。
 * （直すべきデータは画面に「�」で見えるので、そこから直せばよい）
 *
 * ⚠ この関数は `@json` の中身として使われる（AppServiceProvider で差し替え済み）。
 *   画面側で `@json` と書けば自動でこちらが使われるので、書き方は今までどおりでよい。
 */
class SafeJson
{
    /**
     * <script> の中に安全に置ける JSON 文字列にする。**絶対に空を返さない。**
     *
     * 既定の記号（< > & ' "）のエスケープは Blade の @json と同じにそろえてある
     * ＝ 見た目・既存のテストの前提を変えないため。
     */
    public static function forScript($value, int $flags = 0, int $depth = 512): string
    {
        $base = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

        // JSON_INVALID_UTF8_SUBSTITUTE ＝ 壊れた文字を「�」に置き換えて、失敗させない。
        $json = json_encode($value, $base | $flags | JSON_INVALID_UTF8_SUBSTITUTE, $depth);

        if ($json === false) {
            // ここまで来ることはまず無いが、来ても画面は止めない
            //（深すぎる入れ子・循環参照など）。中身が空になるだけで、JSは動き続ける。
            return 'null';
        }

        return $json;
    }
}
