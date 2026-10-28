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
 * 【月ごとの目標】シート「セールス新人イベントおよびD数」「イベプラ新人イベントおよびD数」の2つの表
 *   （イベント数・うちD数）。どちらを使うかは所属（kindOf）。2026-10-13 にイベプラ版を追加。
 *   FCで入る目標＝イベント数 − D数。12ヶ月目より先は12ヶ月目の数を使う。画面で直せる。
 *   ⚠ イベプラの7ヶ月目からは「内D数＝MAX7件まで」＝D数を決めない（null）。FCの目標は
 *     イベント数 − その月に実際にやったD数（DもFCも合わせてイベント数まで、という意味）。
 */
final class Rookies
{
    public const IN = 'in';

    public const OUT = 'out';

    /** 自動で新人として出す所属。 */
    public const DEPARTMENTS = ['イベプラ', 'セールス'];

    /** 自動で出す期間（入社から何ヶ月以内か）。 */
    public const AUTO_MONTHS = 24;

    /** 目標の表の種類（2026-10-13 baba「イベプラの新人の目標もある」）。 */
    public const SALES = 'セールス';

    public const EVENTPLAN = 'イベプラ';

    /** 表ごとの保存先（セールスは前からある鍵のまま＝入れてある値を引き継ぐ）。 */
    private const TARGETS_KEYS = [self::SALES => 'rookie_targets', self::EVENTPLAN => 'rookie_targets_eventplan'];

    /**
     * シートの値（1ヶ月目〜12ヶ月目：[イベント数, うちD数]）。
     * D数が null＝「D数は決めない（MAX◯件まで）」＝DでもFCでも合わせてイベント数まで。
     */
    public const DEFAULT_TARGETS = [
        1 => [10, 0], 2 => [8, 0], 3 => [5, 2], 4 => [5, 3], 5 => [5, 3], 6 => [5, 3],
        7 => [2, 2], 8 => [2, 1], 9 => [2, 1], 10 => [2, 1], 11 => [2, 1], 12 => [2, 1],
    ];

    /** シート「イベプラ新人イベントおよびD数」。7ヶ月目からは「内D数＝MAX7件まで」。 */
    public const DEFAULT_TARGETS_EVENTPLAN = [
        1 => [10, 0], 2 => [10, 0], 3 => [8, 2], 4 => [7, 4], 5 => [7, 4], 6 => [7, 4],
        7 => [7, null], 8 => [7, null], 9 => [7, null], 10 => [7, null], 11 => [7, null], 12 => [7, null],
    ];

    /** どちらの表を使うか＝セールスならセールス、イベプラ（だけ）ならイベプラ。どちらでもなければセールス（前と同じ）。 */
    public static function kindOf(Person $p): string
    {
        $deps = $p->departmentList();

        return ! in_array(self::SALES, $deps, true) && in_array(self::EVENTPLAN, $deps, true)
            ? self::EVENTPLAN : self::SALES;
    }

    private static function defaults(string $kind): array
    {
        return $kind === self::EVENTPLAN ? self::DEFAULT_TARGETS_EVENTPLAN : self::DEFAULT_TARGETS;
    }

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
                // イベプラ・セールスのどちらでもない人は、新人の一覧に出さない（2026-10-28 baba「新人タグを外して」）。
                // ⚠ 手で「新人に入れた」印（rookie_state=in）が残っていても出さない＝印は消さずに隠すだけ。
                if (array_intersect($p->departmentList(), self::DEPARTMENTS) === []) {
                    return false;
                }
                if ($p->rookie_state === self::OUT) {
                    return false;
                }
                if ($p->rookie_state === self::IN) {
                    return true;
                }

                return $p->hire_date && $p->hire_date->gte($limit);
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

    /** @return array<int, array{0:int, 1:?int}> 何ヶ月目 => [イベント数, うちD数（null＝決めない）] */
    public static function targets(string $kind = self::SALES): array
    {
        $defaults = self::defaults($kind);
        $saved = json_decode((string) Setting::get(self::TARGETS_KEYS[$kind] ?? self::TARGETS_KEYS[self::SALES], ''), true);
        if (! is_array($saved)) {
            return $defaults;
        }
        $out = [];
        foreach ($defaults as $m => $def) {
            $v = $saved[$m] ?? $saved[(string) $m] ?? $def;
            $d = array_key_exists(1, $v) ? $v[1] : $def[1];
            $out[$m] = [max(0, (int) ($v[0] ?? $def[0])), $d === null ? null : max(0, (int) $d)];
        }

        return $out;
    }

    /** 画面の表を保存する。D数を空にすると「決めない（MAXまで）」。 */
    public static function saveTargets(array $targets, string $kind = self::SALES): void
    {
        $out = [];
        foreach (self::defaults($kind) as $m => $def) {
            $e = max(0, (int) ($targets[$m]['events'] ?? $def[0]));
            $raw = $targets[$m]['d'] ?? $def[1];
            $out[$m] = [$e, ($raw === null || $raw === '') ? null : max(0, min((int) $raw, $e))];
        }
        Setting::put(self::TARGETS_KEYS[$kind] ?? self::TARGETS_KEYS[self::SALES], json_encode($out));
    }

    /**
     * その月の目標（何ヶ月目か分からなければ null）。
     * D数を決めない月（dFree）は、FCの目標＝イベント数 − その月に実際にやったD数（$actualD）。
     *
     * @return array{events:int, d:?int, fc:int, dFree:bool, kind:string}|null
     */
    public static function targetFor(?int $monthNo, string $kind = self::SALES, int $actualD = 0): ?array
    {
        if ($monthNo === null) {
            return null;
        }
        $t = self::targets($kind);
        [$e, $d] = $t[min($monthNo, 12)];
        $free = $d === null;

        return ['events' => $e, 'd' => $d, 'fc' => max(0, $e - ($free ? $actualD : $d)), 'dFree' => $free, 'kind' => $kind];
    }
}
