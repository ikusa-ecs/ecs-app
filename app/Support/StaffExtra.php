<?php

namespace App\Support;

use App\Models\Project;

/**
 * スタッフ画面で「追加」として上に出すか、の正本（2026-10-07 baba要望）。
 *
 * ⚠ **案件の区分（category＝通常案件／追加案件）とは別もの。**
 *   ・区分 … 案件一覧・アサイン表・集計（追加で何件増えたか）で使う。公開ボードからは触らない。
 *   ・この印 … スタッフ画面の「追加」札・上に出す並び・締切（公開日＋3日）・🔥追加案件のみ（エントリー新着）。
 *   前は公開ボードの「追加」ボタンが区分そのものを書き換えていたので、
 *   スタッフ向けに「追加」を外すと集計からも追加案件が消えていた。
 *
 * staff_extra が null（公開ボードでまだ押していない）＝区分に合わせる。
 */
class StaffExtra
{
    public static function is(Project $p): bool
    {
        if ($p->staff_extra !== null) {
            return (bool) $p->staff_extra;
        }

        return ($p->category ?? '') === '追加案件';
    }

    /** 公開ボードで「追加」を付ける／外す（区分には触らない）。保存は呼ぶ側。 */
    public static function set(Project $p, bool $extra): void
    {
        $p->staff_extra = $extra;
        if ($extra) {
            // すでに公開日があればそのまま（付け直しで締切がずれないように）、無ければ今日を記録。
            if (empty($p->extra_published_at)) {
                $p->extra_published_at = now()->startOfDay();
            }
        } else {
            $p->extra_published_at = null;
        }
    }
}
