<?php

namespace App\Support;

use App\Models\ShiftPreference;
use Illuminate\Support\Carbon;

/**
 * 「その人が、その日に終日〇を出しているか」の正本（2026-09-03 baba要望）。
 *
 * 【なぜ要るか】
 * ⚠ **エントリー（応募）と稼働希望カレンダーは別の入力**。
 *   両方を並べて見ないと、「手は挙げてくれたが、その日はNGにしている」という
 *   食い違いに気づけない（アサインしてから断られる）。
 *
 * ⚠ 出し方（〇／NG／—）を画面ごとに書くと、片方だけ直して食い違う。
 *   使うのはエントリー一覧（/entries）とエントリー新着（/entry-feed）の2画面。
 *
 * 【はまりどころ】
 * ⚠ `shift_preferences.date` は **'2026-09-13 00:00:00' の形（時刻つき）**で入っている。
 *   `whereIn('date', ['2026-09-13'])` では**1件も当たらない**（実際にこれで空になった）。
 *   期間で引いてから、日付の文字にそろえて突き合わせること。
 */
final class ShiftWish
{
    /** availability の文字 → 画面で使う印。ここに無い値（未定など）は「出していない」扱い。 */
    private const CODES = [
        '稼働可' => 'ok',
        '希望' => 'ok',
        'NG' => 'ng',
        '希望休' => 'ng',
    ];

    /**
     * 「本人ID|Y-m-d」→ 'ok'（終日〇）／'ng'（NG・希望休）。
     * 出していない日（未定・行なし）はキーごと入らない＝呼ぶ側は null で受ける。
     *
     * @param  array<int, string>  $staffIds
     * @param  array<int, string>  $days  'Y-m-d' の並び
     * @return array<string, string>
     */
    public static function forDays(array $staffIds, array $days): array
    {
        $staffIds = array_values(array_unique(array_filter($staffIds)));
        $days = array_values(array_unique(array_filter($days)));
        if (! $staffIds || ! $days) {
            return [];
        }

        $rows = ShiftPreference::whereIn('staff_id', $staffIds)
            ->whereBetween('date', [min($days).' 00:00:00', max($days).' 23:59:59'])
            ->get(['staff_id', 'date', 'availability']);

        $want = array_flip($days);
        $out = [];
        foreach ($rows as $r) {
            $day = Carbon::parse($r->date)->format('Y-m-d');
            if (! isset($want[$day])) {
                continue;
            }
            $code = self::CODES[(string) $r->availability] ?? null;
            if ($code !== null) {
                $out[$r->staff_id.'|'.$day] = $code;
            }
        }

        return $out;
    }

    /**
     * 「その日に終日〇を出している人」を日ごとに集めたもの。
     * 返り値＝ 'Y-m-d' => [本人ID, ...]（NG・希望休・未定は入らない）。
     * カレンダー表示（エントリー一覧の「📅 空いている人」）で使う。
     *
     * @return array<string, array<int, string>>
     */
    public static function okStaffByDay(string $from, string $to): array
    {
        $rows = ShiftPreference::whereBetween('date', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get(['staff_id', 'date', 'availability']);

        $out = [];
        foreach ($rows as $r) {
            if ((self::CODES[(string) $r->availability] ?? null) !== 'ok') {
                continue;
            }
            $out[Carbon::parse($r->date)->format('Y-m-d')][] = $r->staff_id;
        }

        return $out;
    }

    /** 1件ぶんの取り出し。日付が無い案件（開催日未定）は null。 */
    public static function of(array $map, string $staffId, ?string $day): ?string
    {
        return $day ? ($map[$staffId.'|'.$day] ?? null) : null;
    }
}
