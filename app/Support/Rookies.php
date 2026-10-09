<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 新人ページの「誰が新人か」「何ヶ月目か」「月ごとの目標」（2026-10-09 baba要望）。
 *
 * 【誰が新人か】（baba「新人は人によって長さが変わる＝入社日だけでは判断できない」）
 *   ・自動で出る＝入社日が2年以内の、イベプラ・セールスの社員（広めに出して、卒業ボタンで外していく）
 *   ・「＋新人に入れる」で手で足した人（rookie_state='in'）
 *   ・「卒業（独り立ち）」を押した人は出さない（rookie_state='out'・取り消せる）
 * 【何ヶ月目か】起点＝rookie_since（空なら入社日）。起点の月を1ヶ月目と数える。
 * 【月ごとの目標】シート「セールス新人イベントおよびD数」の表（イベント数・うちD数）。
 *   FCで入る目標＝イベント数 − D数。12ヶ月目より先は12ヶ月目の数を使う。画面で直せる。
 */
final class Rookies
{
    public const IN = 'in';

    public const OUT = 'out';

    /** 自動で新人として出す所属。 */
    public const DEPARTMENTS = ['イベプラ', 'セールス'];

    /** 自動で出す期間（入社から何ヶ月以内か）。 */
    public const AUTO_MONTHS = 24;

    private const TARGETS_KEY = 'rookie_targets';

    /** シートの値（1ヶ月目〜12ヶ月目：[イベント数, うちD数]）。 */
    public const DEFAULT_TARGETS = [
        1 => [10, 0], 2 => [8, 0], 3 => [5, 2], 4 => [5, 3], 5 => [5, 3], 6 => [5, 3],
        7 => [2, 2], 8 => [2, 1], 9 => [2, 1], 10 => [2, 1], 11 => [2, 1], 12 => [2, 1],
    ];

    /**
     * いま新人として出す人（拠点で絞る）。
     *
     * @return Collection<int, Person>
     */
    public static function list(?string $office = null): Collection
    {
        $limit = Carbon::today()->subMonths(self::AUTO_MONTHS);

        return OfficeScope::applyToPeople(Person::employees()->where('active', true), $office)
            ->orderBy('hire_date')->orderBy('id')->get()
            ->filter(function (Person $p) use ($limit) {
                if ($p->rookie_state === self::OUT) {
                    return false;
                }
                if ($p->rookie_state === self::IN) {
                    return true;
                }

                return $p->hire_date && $p->hire_date->gte($limit)
                    && array_intersect($p->departmentList(), self::DEPARTMENTS) !== [];
            })->values();
    }

    /** 卒業した人（取り消しボタン用）。 */
    public static function graduated(?string $office = null): Collection
    {
        return OfficeScope::applyToPeople(Person::employees()->where('rookie_state', self::OUT), $office)
            ->orderBy('id')->get();
    }

    /** その月に何ヶ月目か（起点が分からなければ null）。 */
    public static function monthNo(Person $p, Carbon $month): ?int
    {
        $base = $p->rookie_since ?? $p->hire_date;
        if (! $base) {
            return null;
        }
        $n = ($month->year - $base->year) * 12 + ($month->month - $base->month) + 1;

        return $n >= 1 ? $n : null;
    }

    /** @return array<int, array{0:int, 1:int}> 何ヶ月目 => [イベント数, うちD数] */
    public static function targets(): array
    {
        $saved = json_decode((string) Setting::get(self::TARGETS_KEY, ''), true);
        if (! is_array($saved)) {
            return self::DEFAULT_TARGETS;
        }
        $out = [];
        foreach (self::DEFAULT_TARGETS as $m => $def) {
            $v = $saved[$m] ?? $saved[(string) $m] ?? $def;
            $out[$m] = [max(0, (int) ($v[0] ?? $def[0])), max(0, (int) ($v[1] ?? $def[1]))];
        }

        return $out;
    }

    public static function saveTargets(array $targets): void
    {
        $out = [];
        foreach (self::DEFAULT_TARGETS as $m => $def) {
            $e = (int) ($targets[$m]['events'] ?? $def[0]);
            $d = (int) ($targets[$m]['d'] ?? $def[1]);
            $out[$m] = [max(0, $e), max(0, min($d, $e))];
        }
        Setting::put(self::TARGETS_KEY, json_encode($out));
    }

    /** @return array{events:int, d:int, fc:int}|null その月の目標（何ヶ月目か分からなければ null） */
    public static function targetFor(?int $monthNo): ?array
    {
        if ($monthNo === null) {
            return null;
        }
        $t = self::targets();
        [$e, $d] = $t[min($monthNo, 12)];

        return ['events' => $e, 'd' => $d, 'fc' => max(0, $e - $d)];
    }
}
