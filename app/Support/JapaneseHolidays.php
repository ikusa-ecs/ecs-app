<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * 日本の祝日を「年ごとに計算する」ための正本。
 *
 * ⚠ なぜ作ったか（2026-09-15）
 *   社員の出勤可能日カレンダーは、祝日を画面に「9/15・9/23・10/13」のように
 *   月日べた書きで持っていた。これは 2025 年の日付で、2026 年は
 *   9/21（敬老の日）・9/22（国民の休日）・9/23（秋分の日）・10/12（スポーツの日）なので
 *   毎年ズレる。ハッピーマンデー（第◯月曜）と春分・秋分は年によって動くため、
 *   固定の一覧では原理的に正しくできない。
 *
 * ⚠ 決まりごと
 *   - 祝日を出す画面が増えても、一覧をコピーしない。必ずここを呼ぶ。
 *   - 春分・秋分の計算式は 1980〜2099 年まで有効（国立天文台の近似式）。
 *     それ以外の年は「その日は祝日ではない」と扱う（間違った日を出すより安全）。
 *   - 振替休日（祝日が日曜のとき翌日以降の平日が休みになる）と
 *     国民の休日（祝日にはさまれた平日。例＝2026/9/22）も計算に入れている。
 */
class JapaneseHolidays
{
    /** 春分・秋分の近似式が使える範囲。 */
    private const MIN_YEAR = 1980;

    private const MAX_YEAR = 2099;

    /** 計算した結果を年ごとに覚えておく（同じ年を何度も計算しないため）。 */
    private static array $cache = [];

    /**
     * 1年ぶんの祝日。
     *
     * @return array<string,string> "Y-n-j"（例 "2026-9-21"）=> 祝日名
     */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }
        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            return self::$cache[$year] = [];
        }

        // ① まず「その年の決まった祝日」を並べる。キーは M-D（この段階では日付順でなくてよい）。
        $days = self::fixedAndHappyMondays($year);

        // ② 振替休日。祝日が日曜なら、次の「まだ祝日でない日」を振替休日にする。
        //    ⚠ 並び順に依存しないよう、日付順にそろえてから見る。
        ksort($days);
        foreach (array_keys($days) as $key) {
            $d = self::parse($year, $key);
            if ($d->dayOfWeek !== Carbon::SUNDAY) {
                continue;
            }
            $next = $d->copy()->addDay();
            while (isset($days[self::key($next)])) {
                $next->addDay();
            }
            // 年をまたぐ振替（12/31が日曜の祝日）は、その年の一覧には入れない。
            if ($next->year === $year) {
                $days[self::key($next)] = '振替休日';
            }
        }

        // ③ 国民の休日。祝日と祝日に1日だけはさまれた平日は休みになる。
        //    例＝2026/9/22（敬老の日 9/21 と 秋分の日 9/23 のあいだ）。
        ksort($days);
        foreach (array_keys($days) as $key) {
            $d = self::parse($year, $key);
            $gap = $d->copy()->addDay();      // はさまれる候補
            $after = $d->copy()->addDays(2);  // その次が祝日か
            if ($gap->year !== $year || $after->year !== $year) {
                continue;
            }
            if (isset($days[self::key($gap)])) {
                continue; // もう祝日
            }
            if ($gap->dayOfWeek === Carbon::SUNDAY) {
                continue; // 日曜は国民の休日にならない
            }
            if (! isset($days[self::key($after)])) {
                continue;
            }
            $days[self::key($gap)] = '国民の休日';
        }

        // ④ 画面が使う "Y-n-j" 形に直して返す。
        $out = [];
        ksort($days);
        foreach ($days as $key => $name) {
            $d = self::parse($year, $key);
            $out[$d->year.'-'.$d->month.'-'.$d->day] = $name;
        }

        return self::$cache[$year] = $out;
    }

    /**
     * 複数年ぶんをまとめて。カレンダーは前後の月へ動かせるので、画面には数年ぶん渡す。
     *
     * @param  int[]  $years
     * @return array<string,string> "Y-n-j" => 祝日名
     */
    public static function forYears(array $years): array
    {
        $out = [];
        foreach ($years as $y) {
            $out += self::forYear((int) $y);
        }

        return $out;
    }

    /**
     * 画面に渡す既定の範囲＝去年から再来年まで（4年ぶん）。
     * カレンダーは当月から半年先まで動かせるので、年末に開いても足りるようにしてある。
     *
     * @return array<string,string>
     */
    public static function forDisplay(?Carbon $today = null): array
    {
        $y = ($today ?: Carbon::today())->year;

        return self::forYears([$y - 1, $y, $y + 1, $y + 2]);
    }

    /** その日が祝日かどうか（祝日名 or null）。 */
    public static function nameFor(Carbon $date): ?string
    {
        $map = self::forYear($date->year);

        return $map[$date->year.'-'.$date->month.'-'.$date->day] ?? null;
    }

    /**
     * 年によって動かない祝日＋ハッピーマンデー＋春分・秋分。
     *
     * @return array<string,string> "MM-DD" => 名前
     */
    private static function fixedAndHappyMondays(int $year): array
    {
        $days = [];
        $put = function (int $m, int $d, string $name) use (&$days) {
            $days[sprintf('%02d-%02d', $m, $d)] = $name;
        };

        $put(1, 1, '元日');
        $put(1, self::nthMonday($year, 1, 2), '成人の日');
        $put(2, 11, '建国記念の日');
        $put(2, 23, '天皇誕生日'); // 2020年から。それ以前の年をさかのぼって使う予定は無い。
        $put(3, self::vernalEquinox($year), '春分の日');
        $put(4, 29, '昭和の日');
        $put(5, 3, '憲法記念日');
        $put(5, 4, 'みどりの日');
        $put(5, 5, 'こどもの日');
        $put(7, self::nthMonday($year, 7, 3), '海の日');
        $put(8, 11, '山の日');
        $put(9, self::nthMonday($year, 9, 3), '敬老の日');
        $put(9, self::autumnalEquinox($year), '秋分の日');
        $put(10, self::nthMonday($year, 10, 2), 'スポーツの日');
        $put(11, 3, '文化の日');
        $put(11, 23, '勤労感謝の日');

        return $days;
    }

    /** その月の第n月曜の「日」を返す（ハッピーマンデー用）。 */
    private static function nthMonday(int $year, int $month, int $nth): int
    {
        $first = Carbon::create($year, $month, 1);
        // 1日の曜日から、最初の月曜までの日数を出す（0=日..6=土）。
        $shift = (Carbon::MONDAY - $first->dayOfWeek + 7) % 7;

        return 1 + $shift + ($nth - 1) * 7;
    }

    /** 春分の日（国立天文台の近似式・1980〜2099）。 */
    private static function vernalEquinox(int $year): int
    {
        return (int) floor(20.8431 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    }

    /** 秋分の日（同上）。 */
    private static function autumnalEquinox(int $year): int
    {
        return (int) floor(23.2488 + 0.242194 * ($year - 1980) - floor(($year - 1980) / 4));
    }

    /** "MM-DD" を Carbon に。 */
    private static function parse(int $year, string $key): Carbon
    {
        [$m, $d] = array_map('intval', explode('-', $key));

        return Carbon::create($year, $m, $d)->startOfDay();
    }

    /** Carbon を "MM-DD" に。 */
    private static function key(Carbon $d): string
    {
        return sprintf('%02d-%02d', $d->month, $d->day);
    }
}
