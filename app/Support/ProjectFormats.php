<?php

namespace App\Support;

/**
 * 実施形態（リアル／リアルロング／オンライン／ARENA場所貸し／体験会）の正本。2026-08-27。
 *
 * 【なぜ要るか】
 * ⚠ それまで実施形態の一覧と判定が**6か所に散らばっていた**。1つ増やすと6か所直す必要があり、
 *   実際に「集計だけ古い形のまま」で全部その他になる不具合が起きている（2026-08-26 に修正済み）。
 *   ここを直せば全部に効く、という場所を1つ作る。
 *
 * 【3つの分け方がある。混ぜないこと】
 *  ① ALL          … 案件登録で選べる5つ。**増やすときはここに1行**
 *  ② countCode()  … 件数の集計用（real / long / online）。トップの「今月の件数集計」など
 *  ③ typeCode()   … アサイン表の日付ヘッダーの色分け用（basho / tokyo / tohoku / help / …）
 *  ④ badgeCode()  … 案件一覧のバッジの色分け用
 * ②③④は目的が違うので**わざと別**にしてある（1つにまとめると、色を変えたいだけで集計が動く）。
 *
 * 【まだここに寄せていないもの】
 * ⚠ `public/ecs/data/cases.js` の `window.ECS_fmtCode` が ② と同じ判定を持っている
 *   （危険日の警告で使う。ダッシュボード・案件登録・D決めが読み込んでいる）。
 *   cases.js は凍結ファイルなので触っていない。**② を変えるときは cases.js も合わせること。**
 */
final class ProjectFormats
{
    /**
     * 案件登録で選べる実施形態（正本）。
     * ⚠ 増やすときはここに1行。案件登録の選択肢・案件一覧の絞り込み・
     *   月シート取込の読み取り（MonthlySheetReader）に同時に効く。
     */
    public const ALL = ['リアル', 'リアルロング', 'オンライン', 'ARENA場所貸し', '体験会'];

    /**
     * 件数の集計に使う種別コード（real / long / online）。
     *
     * ⚠ ARENA・体験会・巻き取りは**リアル系として数える**（従来どおり）。
     *   「数える／数えない」は実施形態ではなく App\Support\EventCount が決める。
     *
     * ⚠⚠ **「ヘルプのみ」はオンラインではない**（2026-09-01 baba訂正）。
     *   ヘルプのみ＝**どの拠点が手伝うか**という運用の話で、実施形態ではない。
     *   リアルのイベントは、ヘルプで入っていても**リアル**。実施形態は紐づくイベントに合わせる。
     *   取込側も同じ扱い（`MonthlySheetReader::CROSS_OFFICE`＝拠点をまたいだ関わりとして読む）。
     *   → ここでは何もしない＝「オンライン」と書いてあればオンライン、無ければリアル系に落ちる。
     *   `public/ecs/data/cases.js` の `window.ECS_fmtCode`（危険日の警告で使う）も
     *   2026-09-01 に同じ規則へそろえた（baba了解のうえ、その1か所だけ触った）。
     */
    public static function countCode(?string $format): string
    {
        $t = (string) $format;

        if (str_contains($t, 'オンライン')) {
            return 'online';
        }
        if (str_contains($t, 'リアルロング')) {
            return 'long';
        }

        return 'real';
    }

    /**
     * アサイン表（/assign-sheet）の日付ヘッダーの色分けキー。
     *
     * 例：「イベント東(リアルロング)」→ long ／「ARENA場所貸し」→ basho ／
     *     「イベント他拠点→東(巻き取り)」→ tokyo。
     * どれにも当てはまらない（未設定など）は other（既定色）。
     *
     * ⚠ 並び順に意味がある（上から順に見る）。「リアルロング」を「リアル」より先に見ないと、
     *   ロングがリアルとして扱われる。
     */
    public static function typeCode(?string $format): string
    {
        $f = (string) $format;

        return match (true) {
            str_contains($f, '場所貸し') || str_contains($f, 'ARENA') => 'basho',
            str_contains($f, '他拠点→東') || str_contains($f, '他拠点⇒東') => 'tokyo',
            str_contains($f, '東北') => 'tohoku',   // 東北の案件は専用色で区別する（baba 2026-07-24）
            str_contains($f, 'ヘルプ') => 'help',
            str_contains($f, '体験') => 'taiken',
            str_contains($f, 'ロング') => 'long',
            str_contains($f, 'オンライン') => 'online',
            str_contains($f, 'リアル') => 'real',
            default => 'other',
        };
    }

    /**
     * 案件一覧のバッジのCSSクラス（色分け）。
     * ⚠ キャンセルの案件は実施形態の値を消さずにバッジだけ「キャンセル」にする（2026-08-26）。
     *   その判定は画面側（fmtBadge）で行うので、ここでは扱わない。
     */
    public static function badgeCode(?string $format): string
    {
        $f = (string) $format;

        return match (true) {
            str_contains($f, 'ARENA') => 'fmt-arena',
            str_contains($f, 'オンライン') => 'fmt-online',
            str_contains($f, '他拠点') => 'fmt-other',
            str_contains($f, 'リアルロング') => 'fmt-long',
            str_contains($f, 'リアル') => 'fmt-real',
            default => 'fmt-etc',
        };
    }
}
