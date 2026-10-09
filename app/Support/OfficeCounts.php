<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Project;

/**
 * 拠点ごとの必要人数の正本（2026-10-09 baba要望「日別ボードで東1 名9のように変えられたら最高」）。
 *
 * 【どういうときに使うか】
 * ヘルプ・巻き取りで2つ以上の拠点が関わる案件で、「東京から1名・名古屋から9名」のように分けたいとき。
 * 保存先は projects.office_counts（例 {"東京":1,"名古屋":9}）。
 *
 * 【決まり（baba決定 2026-10-09）】
 *   ・どちらの拠点からでも直せる。巻き取りでも普通のヘルプでも同じ。
 *   ・**数えるのも拠点ごと（A案）**＝東京の画面では「東京の人が何人入ったか」で満員を決める。
 *     名古屋の人で埋まっても、東京の募集は締まらない。
 *   ・派遣はどの拠点の分か分からないので、**登録した拠点の分**として数える。
 *   ・入っていない拠点・全拠点表示のときは、これまでどおり運営人数（required_count）で動く。
 * ⚠ 関わっていない拠点の数は捨てる（ヘルプを外したあとに古い数が残って効かないように）。
 */
final class OfficeCounts
{
    /**
     * その案件に関わる拠点（登録拠点＋ヘルプ・巻き取りの拠点）。並びは登録拠点が先。
     *
     * @param  iterable|null  $shares  その案件の project_shares（無ければ引く）
     * @return list<string>
     */
    public static function offices(Project $project, ?iterable $shares = null): array
    {
        $shares ??= $project->shares()->get();
        $list = [self::owner($project)];
        foreach ($shares as $s) {
            $o = trim((string) ($s->office ?? ''));
            if ($o !== '' && ! in_array($o, $list, true)) {
                $list[] = $o;
            }
        }

        return $list;
    }

    /**
     * 入っている拠点ごとの人数（関わっている拠点だけ・数字だけ）。
     *
     * @return array<string, int>
     */
    public static function of(Project $project, ?iterable $shares = null): array
    {
        $raw = is_array($project->office_counts) ? $project->office_counts : [];
        $out = [];
        foreach (self::offices($project, $shares) as $o) {
            if (isset($raw[$o]) && is_numeric($raw[$o])) {
                $out[$o] = max(0, (int) $raw[$o]);
            }
        }

        return $out;
    }

    /** その拠点の必要人数。分けていない・全拠点表示なら null（＝運営人数を使う）。 */
    public static function needFor(Project $project, ?string $office, ?iterable $shares = null): ?int
    {
        if (! $office) {
            return null;
        }

        return self::of($project, $shares)[$office] ?? null;
    }

    /** その人はその拠点の人か（拠点が空の人は東京あつかい＝OfficeScope と同じ）。 */
    public static function personIs(?Person $person, string $office): bool
    {
        return OfficeScope::personIn($person, $office);
    }

    /** 派遣をその拠点の分として数えるか（登録した拠点の分＝baba決定）。 */
    public static function dispatchCountsFor(Project $project, string $office): bool
    {
        return self::owner($project) === $office;
    }

    /** 「東京1・名古屋9」。分けていなければ空。 */
    public static function label(array $counts): string
    {
        $parts = [];
        foreach ($counts as $o => $n) {
            $parts[] = $o.$n;
        }

        return implode('・', $parts);
    }

    private static function owner(Project $project): string
    {
        return trim((string) ($project->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
    }
}
