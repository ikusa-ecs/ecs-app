<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * 繁忙期ボーナス（アクティブダッシュボード）の正本。2026-09-17 baba要望。
 *
 * ねらい＝**繁忙期に、自社スタッフにもう1回出てもらう**こと。
 *   スタッフが足りないぶんをタイミー（スポットのアルバイト）で埋めると1回あたりの費用が高い。
 *   それより安い金額でボーナスを付けて自社スタッフに出てもらえれば、その差額が会社の削減になる。
 *   ＝「あと1回でボーナスが付く人」に優先してアサインすると、いちばん得になる。
 *
 * 【計算のきまり】（画像の見本から逆算し、2026-09-17 baba が「このとおりでOK」と確認）
 *   ・回数が階層（既定 5回／10回／15回）に届くと、時給が上がる（既定 +500／+1,000／+1,500円/h）。
 *   ・上がった時給は **その月の全回数にさかのぼって** 効く。
 *     例）9回の人は5回階層 → 9回 × 6時間 × 500円 = 27,000円
 *   ・1回あたりの時間は既定6時間。
 *   ・タイミー利用時コスト＝対象者の回数の合計 × タイミー1回あたりの費用。
 *   ・削減見込額＝タイミー利用時コスト − IKUSA追加コスト（ボーナスの合計）。
 *
 * 【数え方のきまり】（2026-09-17 に決めた）
 *   ・回数＝**「確定」のアサインだけ**。仮置き・キャンセルは数えない（お金が動くため）。
 *     ⚠ 集計ダッシュボード(/stats)の出勤数は「キャンセル以外」なので、数が一致しないことがある。
 *   ・対象者＝**その月に確定アサインが1回以上あるスタッフ**（社員は除く・baba選択）。
 *     ⚠ 名簿の全スタッフを対象にすると、0回の人が何十人も並んで達成率が意味を持たなくなる。
 *   ・拠点＝**スタッフの所属拠点**で分ける（ボーナスを払うのはその人の拠点なので）。
 *     ⚠ 案件の拠点ではない。/stats（案件の拠点で分ける）とは考え方が違う。
 *
 * ⚠ 階層・単価・時間・タイミー費用は共通設定(/settings)から変えられる。
 *   画面やテストに数字を直書きしないこと。ここ1か所を見る。
 */
final class ActiveBonus
{
    /** 設定のキー（settings テーブル）。 */
    public const KEY_TIERS = 'active_bonus_tiers';
    public const KEY_HOURS = 'active_bonus_hours';
    public const KEY_SPOT_COST = 'active_bonus_spot_cost';
    public const KEY_ENABLED = 'active_bonus_enabled';

    /** 既定の階層＝[達成回数, 上がる時給(円/h)]。画像の見本と同じ。 */
    public const DEFAULT_TIERS = [[5, 500], [10, 1000], [15, 1500]];

    /** 1回あたりの時間（既定6時間）。 */
    public const DEFAULT_HOURS = 6;

    /**
     * タイミー1回あたりの費用（既定10,000円・手数料込みのつもり）。
     * ⚠ 画像の見本から逆算すると 10,338円 だったが、根拠が分からないので既定は切りのよい数にした。
     *   実際の数字は設定画面で入れてもらう。
     */
    public const DEFAULT_SPOT_COST = 10000;

    // ────────────────────────────────── 設定の読み書き

    /**
     * 階層の一覧（回数の少ない順）。
     *
     * @return list<array{count:int, rate:int}>
     */
    public static function tiers(): array
    {
        $raw = trim((string) Setting::get(self::KEY_TIERS, ''));
        $pairs = [];

        if ($raw !== '') {
            // 保存の形＝「5:500,10:1000,15:1500」。壊れた行は黙って捨てる（画面が落ちないように）。
            foreach (explode(',', $raw) as $part) {
                $bits = explode(':', trim($part));
                if (count($bits) !== 2) {
                    continue;
                }
                $count = (int) trim($bits[0]);
                $rate = (int) trim($bits[1]);
                if ($count > 0 && $rate > 0) {
                    $pairs[] = [$count, $rate];
                }
            }
        }

        if ($pairs === []) {
            $pairs = self::DEFAULT_TIERS;
        }

        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);

        return array_map(fn ($p) => ['count' => $p[0], 'rate' => $p[1]], $pairs);
    }

    /** 1回あたりの時間。 */
    public static function hours(): int
    {
        $n = (int) Setting::get(self::KEY_HOURS, 0);

        return $n > 0 ? $n : self::DEFAULT_HOURS;
    }

    /** タイミー1回あたりの費用（円）。 */
    public static function spotCost(): int
    {
        $n = (int) Setting::get(self::KEY_SPOT_COST, -1);

        // ⚠ 0 は「比較を出さない」という意味で使えるようにするので、未設定(-1)だけ既定に落とす。
        return $n >= 0 ? $n : self::DEFAULT_SPOT_COST;
    }

    /**
     * いま繁忙期ボーナスをやっているか。
     * OFF のあいだも画面は開ける（数字は出る）が、左メニューとスタッフ画面には出さない
     * ＝「やっていないのに、あと1回でボーナスと出る」事故を防ぐ。
     */
    public static function enabled(): bool
    {
        return (string) Setting::get(self::KEY_ENABLED, '') === '1';
    }

    /**
     * 設定をまとめて保存する。
     *
     * @param  list<array{count:int, rate:int}>  $tiers
     */
    public static function save(array $tiers, int $hours, int $spotCost, bool $enabled): void
    {
        $clean = [];
        foreach ($tiers as $t) {
            $count = (int) ($t['count'] ?? 0);
            $rate = (int) ($t['rate'] ?? 0);
            if ($count > 0 && $rate > 0) {
                $clean[$count] = $rate;   // 同じ回数を2行入れたら後の行で上書き（重複させない）
            }
        }
        ksort($clean);

        $parts = [];
        foreach ($clean as $count => $rate) {
            $parts[] = $count.':'.$rate;
        }

        Setting::put(self::KEY_TIERS, implode(',', $parts));
        Setting::put(self::KEY_HOURS, (string) max(1, $hours));
        Setting::put(self::KEY_SPOT_COST, (string) max(0, $spotCost));
        Setting::put(self::KEY_ENABLED, $enabled ? '1' : '');
    }

    // ────────────────────────────────── 1人ぶんの計算

    /** その回数で達成している時給の上がり幅（円/h）。まだどの階層にも届いていなければ0。 */
    public static function rateFor(int $count): int
    {
        $rate = 0;
        foreach (self::tiers() as $t) {
            if ($count >= $t['count']) {
                $rate = $t['rate'];
            }
        }

        return $rate;
    }

    /**
     * 次に届く階層。いちばん上まで届いていれば null。
     *
     * @return array{count:int, rate:int, remain:int}|null
     */
    public static function nextTier(int $count): ?array
    {
        foreach (self::tiers() as $t) {
            if ($count < $t['count']) {
                return ['count' => $t['count'], 'rate' => $t['rate'], 'remain' => $t['count'] - $count];
            }
        }

        return null;
    }

    /** その回数でもらえるボーナスの合計（円）＝回数 × 1回の時間 × 上がり幅。 */
    public static function bonusFor(int $count): int
    {
        return $count * self::hours() * self::rateFor($count);
    }

    /** 達成にあと1回の人か（＝優先してアサインすると得になる人）。 */
    public static function isOneMore(int $count): bool
    {
        $next = self::nextTier($count);

        return $next !== null && $next['remain'] === 1;
    }

    // ────────────────────────────────── 月ぶんの集計

    /**
     * 指定した月・拠点の集計。画面はこれをそのまま出すだけにする。
     *
     * @param  string  $month  'YYYY-MM'
     * @param  string|null  $office  null＝全拠点
     * @return array<string, mixed>
     */
    public static function summary(string $month, ?string $office): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        // 対象になりうる人＝スタッフ（社員は除く・baba選択）。拠点はその人の所属で絞る。
        $people = OfficeScope::applyToPeople(
            Person::query()->where('role', 'staff'),
            $office
        )->get(['id', 'name', 'office', 'is_spot', 'active']);

        $countByStaff = Assignment::query()
            ->confirmed()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('staff_id', $people->pluck('id')->all())
            ->get(['staff_id'])
            ->groupBy('staff_id')
            ->map(fn ($g) => $g->count());

        $hours = self::hours();
        $spotCost = self::spotCost();

        // 対象者＝その月に1回以上ある人だけ。0回の人を並べると達成率が意味を持たなくなる。
        $rows = $people
            ->filter(fn (Person $p) => ($countByStaff[$p->id] ?? 0) > 0)
            ->map(function (Person $p) use ($countByStaff) {
                $count = (int) ($countByStaff[$p->id] ?? 0);
                $next = self::nextTier($count);

                return [
                    'id' => $p->id,
                    'name' => (string) $p->name,
                    'office' => (string) ($p->office ?: ''),
                    'isSpot' => (bool) $p->is_spot,
                    'count' => $count,
                    'rate' => self::rateFor($count),
                    'bonus' => self::bonusFor($count),
                    'next' => $next,
                    'oneMore' => $next !== null && $next['remain'] === 1,
                ];
            })
            ->values();

        $totalCount = (int) $rows->sum('count');
        $bonusTotal = (int) $rows->sum('bonus');
        $spotTotal = $totalCount * $spotCost;
        $achieved = $rows->filter(fn ($r) => $r['rate'] > 0);

        return [
            'month' => $month,
            'monthLabel' => $start->format('Y年n月'),
            'tiers' => self::tiers(),
            'hours' => $hours,
            'spotCost' => $spotCost,
            'enabled' => self::enabled(),

            // KPI
            'targetCount' => $rows->count(),
            'achievedCount' => $achieved->count(),
            'achieveRate' => $rows->count() > 0 ? (int) round($achieved->count() / $rows->count() * 100) : 0,
            'oneMoreCount' => $rows->filter(fn ($r) => $r['oneMore'])->count(),
            'totalCount' => $totalCount,
            'bonusTotal' => $bonusTotal,
            'spotTotal' => $spotTotal,
            'saving' => $spotTotal - $bonusTotal,

            // 表
            'oneMore' => $rows->filter(fn ($r) => $r['oneMore'])->sortByDesc('count')->values(),
            'nextRows' => $rows->filter(fn ($r) => $r['next'] !== null)->sortByDesc('count')->values(),
            'ranking' => $rows->sortByDesc('count')->values(),
        ];
    }

    /**
     * スタッフ本人に見せる1人ぶん（スタッフ画面用）。
     * ⚠ 金額のうち出すのは**自分のボーナスだけ**。会社のコスト・削減額はスタッフに出さない。
     *
     * @return array{count:int, rate:int, bonus:int, next:array{count:int,rate:int,remain:int}|null, hours:int}
     */
    public static function forStaff(string $staffId, string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = (clone $start)->endOfMonth();

        $count = Assignment::query()
            ->confirmed()
            ->where('staff_id', $staffId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->count();

        return [
            'count' => $count,
            'rate' => self::rateFor($count),
            'bonus' => self::bonusFor($count),
            'next' => self::nextTier($count),
            'hours' => self::hours(),
        ];
    }
}
