<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * 入社年月日（people.hire_date）の「入れ方」の正本（2026-09-03 baba要望）。
 *
 * 【なぜ要るか】
 * ⚠ これまで全部の画面が `<input type="date">` 1個だった。
 *   これが「入れにくい」と言われた原因：
 *     ・カレンダーが**今月**から開く＝2018年まで戻すのに何十回も「前の月」を押すことになる
 *     ・スマホだと年・月・日を細いドラムで合わせることになる
 *     ・「日にちが分からない」人でも日を必ず選ばされる
 * ⚠ そこで **年・月・日の3つのプルダウン**に変えた。日は「1日」が最初から入っている。
 *
 * 【送られてくる形】
 *   画面からは `hire_y` / `hire_m` / `hire_d` の3つが届く。
 *   これを [[\App\Http\Middleware\NormalizeHireDate]] が `hire_date`（Y-m-d）に組み立て直す。
 *   ⚠ **組み立てはここ1か所だけ**。各コントローラは今までどおり `hire_date` を見るだけでよい
 *     （入れられる画面が4つある＝初期設定・マイページ・マイプロフィール・名簿・スタッフ画面。
 *       画面ごとに書き写すと、片方だけ直して食い違う）。
 */
final class HireDate
{
    /**
     * 選べる一番古い年。
     * ⚠ 生年月日を入れてしまう事故が実際に起きているので、**わざと生まれ年が選べない範囲**にしてある。
     *   （いま登録されている一番古い入社は2018年。余裕をみて2005年まで）
     */
    public const FIRST_YEAR = 2005;

    /**
     * 「年は選んだのに月を選んでいない」ときに入れる、わざと日付として読めない値。
     * ⚠ ここで勝手に1月にしてしまうと、社歴の並び順が静かに何か月もずれる。
     *   保存させずに「月を選んでください」と出したいので、あえて壊れた値を返す。
     */
    public const INCOMPLETE = '0000-00-00';

    /** プルダウンに出す年の一覧（新しい順＝たいていは最近の入社なので上にある方が早い）。 */
    public static function years(): array
    {
        $to = (int) CarbonImmutable::now()->year + 1; // 4月入社の予定などを先に入れられるように+1年
        $out = [];
        for ($y = $to; $y >= self::FIRST_YEAR; $y--) {
            $out[] = $y;
        }

        return $out;
    }

    /**
     * 年・月・日 → 'Y-m-d'。
     *
     * ・年が空 …… null を返す（＝未入力。空欄として扱われる）
     * ・月が空 …… self::INCOMPLETE を返す（＝入力チェックで止める）
     * ・日が空 …… 1日として扱う（「日にちが分からなければ1日で構いません」という運用のまま）
     */
    public static function compose(mixed $y, mixed $m, mixed $d): ?string
    {
        $y = trim((string) $y);
        $m = trim((string) $m);
        $d = trim((string) $d);

        if ($y === '') {
            return null;
        }
        if ($m === '') {
            return self::INCOMPLETE;
        }
        if ($d === '') {
            $d = '1';
        }

        // 2月30日のような「無い日」は、その月の最後の日に寄せる（選び間違いで保存できないより親切）。
        $last = (int) date('t', mktime(0, 0, 0, max(1, min(12, (int) $m)), 1, (int) $y));
        $day = max(1, min($last, (int) $d));

        return sprintf('%04d-%02d-%02d', (int) $y, (int) $m, $day);
    }

    /**
     * 'Y-m-d' → ['y' => '2024', 'm' => '4', 'd' => '1']。
     * 画面のプルダウンに、保存済みの値を選んだ状態で出すために使う。空なら全部 ''。
     */
    public static function parts(mixed $date): array
    {
        $s = trim((string) $date);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $mt)) {
            return ['y' => '', 'm' => '', 'd' => ''];
        }

        return ['y' => (string) (int) $mt[1], 'm' => (string) (int) $mt[2], 'd' => (string) (int) $mt[3]];
    }
}
