<?php

namespace App\Support;

use App\Models\Project;
use App\Models\ProjectShare;

/**
 * スタッフへの公開を「拠点ごと」に扱う正本（2026-09-30 baba要望「スタッフ公開ボードは拠点ごとにしてほしい」）。
 *
 * ・projects.published_offices＝どの拠点のスタッフに公開しているか（例 ["名古屋","東京"]）。
 * ・projects.staff_published＝どこかの拠点で公開中か（自動アサインの対象・集計・確定アサインの表示はこれを見る）。
 * ・⚠ published_offices が null＝これまでどおり「関わる全拠点に公開」（staff_published が true のとき）。
 *   今もう公開している案件を急に見えなくしないため。公開ボードで次に押したときから拠点ごとになる。
 *
 * ⚠ スタッフの募集一覧・公開ボードの「公開中」・日別ボードの「公開中」は、必ずここを通す（画面で判定し直さない）。
 */
final class OfficePublish
{
    /** その拠点のスタッフに公開しているか。$office が空＝どこかで公開していれば true。 */
    public static function isPublishedFor(Project $p, ?string $office): bool
    {
        if (! $p->staff_published) {
            return false;
        }
        if ($office === null || $office === '') {
            return true;
        }

        return in_array($office, self::currentList($p), true);
    }

    /**
     * その拠点で公開する／非公開にする（公開ボードの1押し）。変わったら true。
     * ⚠ 保存は呼ぶ側で $p->save()（編集履歴に残すため）。
     */
    public static function set(Project $p, string $office, bool $on): bool
    {
        $list = self::currentList($p);
        $has = in_array($office, $list, true);
        if ($has === $on && is_array($p->published_offices)) {
            return false;
        }
        if ($on && ! $has) {
            $list[] = $office;
        }
        if (! $on) {
            $list = array_values(array_filter($list, fn ($o) => $o !== $office));
        }
        $before = [(bool) $p->staff_published, $p->published_offices];
        $p->published_offices = array_values(array_unique($list));
        $p->staff_published = $list !== [];

        return $before !== [(bool) $p->staff_published, $p->published_offices];
    }

    /**
     * いま公開している拠点の一覧。null（これまでの形）なら「関わる全拠点」＝登録拠点＋ヘルプ・巻き取りの拠点。
     *
     * @return list<string>
     */
    public static function currentList(Project $p): array
    {
        if (is_array($p->published_offices)) {
            return array_values($p->published_offices);
        }
        if (! $p->staff_published) {
            return [];
        }
        $owner = trim((string) ($p->office ?? '')) !== '' ? (string) $p->office : OfficeScope::DEFAULT_OFFICE;
        $shares = ProjectShare::where('project_id', $p->id)->get(['office', 'kind', 'origin_helps']);
        // ⚠ ほかの拠点に巻き取られていて「自拠点からも人を出す」が無ければ、登録した拠点は入れない（2026-09-30）。
        $takenAway = $shares->contains(fn ($s) => $s->kind === '巻き取り' && $s->office !== $owner && ! $s->origin_helps);

        return array_values(array_unique(array_merge(
            $takenAway ? [] : [$owner],
            $shares->pluck('office')->filter()->all()
        )));
    }

    /**
     * 案件の問い合わせを「その拠点のスタッフに公開中」に絞る。
     * null（これまでの形）は「関わる全拠点」＝ただし**ほかの拠点に巻き取られた案件は、登録した拠点のスタッフには出さない**
     * （「自拠点からも人を出す」が付いていれば出す）。2026-09-30 baba「スタッフの画面に他拠点巻き取りの案件も出てる」。
     * ⚠ 決まりは OfficeScope::hideTakenOver と同じにする（日別ボード・公開ボードと食い違わないように）。
     */
    public static function scopePublishedFor($query, string $office)
    {
        return $query->where('staff_published', true)
            ->where(fn ($q) => $q->where(fn ($legacy) => OfficeScope::hideTakenOver($legacy->whereNull('published_offices'), $office, true))
                ->orWhereJsonContains('published_offices', $office));
    }
}
