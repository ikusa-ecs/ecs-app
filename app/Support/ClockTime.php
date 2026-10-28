<?php

namespace App\Support;

/**
 * 時刻の書き方を「07:00」の形にそろえる（2026-10-28）。
 *
 * 【なぜ要るか】スタッフ公開ボードは「7:00」のように0なしで保存していた。
 *   案件の編集画面の時間欄（type="time"）は「07:00」の形しか出せないので、
 *   7:00 は空欄に見え、編集画面を保存するとスタッフの時間が消えていた。
 * ⚠ 時刻の形でないもの（未定・空白など）は、そのまま返す（勝手に変えない）。
 */
final class ClockTime
{
    /** 「7:00」→「07:00」。空は null。時刻の形でなければそのまま。 */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $value;
    }
}
