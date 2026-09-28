<?php

namespace App\Support;

/**
 * 集合〜解散の「拘束時間」を出す（2026-09-28 baba要望・アサイン表）。
 *
 * ⚠ 時間の欄は自由記述の文字（`9:00` `09:00` `9：00`（全角コロン）など）なので、
 *   数字として読めなければ**黙って空を返す**。推測で埋めない
 *   （間違った拘束時間が出ると、そのまま人に伝わってしまう）。
 *
 * ⚠ 解散が集合より前のときは「日をまたいだ」とみなして24時間足す。
 *   夜のイベントで 21:00 集合 → 翌 1:00 解散、が実際にある。
 *   ただし**12時間を超える差**になるときだけまたぎとみなす、といった細工はしない
 *   （かえって読めなくなる）。おかしな値は見れば分かるので、そのまま出す。
 */
class WorkSpan
{
    /** 「9時間30分」の形。読めないときは空文字。 */
    public static function label(?string $start, ?string $end): string
    {
        $m = self::minutes($start, $end);
        if ($m === null) {
            return '';
        }

        $h = intdiv($m, 60);
        $mi = $m % 60;

        if ($h === 0) {
            return $mi.'分';
        }

        return $mi === 0 ? $h.'時間' : $h.'時間'.$mi.'分';
    }

    /** 拘束の分数。どちらかが読めなければ null。 */
    public static function minutes(?string $start, ?string $end): ?int
    {
        $s = self::toMinutes($start);
        $e = self::toMinutes($end);
        if ($s === null || $e === null) {
            return null;
        }

        if ($e < $s) {
            $e += 24 * 60;   // 日をまたいだ
        }

        return $e - $s;
    }

    /** 「9:00」→ 540。読めなければ null。全角の数字・コロンも受ける。 */
    private static function toMinutes(?string $time): ?int
    {
        $t = trim((string) $time);
        if ($t === '') {
            return null;
        }

        // 全角 → 半角（実データに全角コロンが混ざっている）。
        $t = strtr($t, [
            '：' => ':', '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);

        if (! preg_match('/^(\d{1,2})\s*[:：時]\s*(\d{1,2})/u', $t, $m)) {
            return null;
        }

        $h = (int) $m[1];
        $mi = (int) $m[2];
        if ($h > 47 || $mi > 59) {
            return null;
        }

        return $h * 60 + $mi;
    }
}
