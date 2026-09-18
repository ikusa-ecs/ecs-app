<?php

namespace App\Support;

use App\Models\Project;

/**
 * 「この案件は配信・中継があるか」の正本（2026-09-18・FBシート No.21 馬場さん）。
 *
 * 【なぜ要るか】
 * ⚠ 配信の有無は**2か所に分かれて保存されている**。
 *   ① 通常の案件 … projects.broadcast ＝「なし／配信／中継」（案件登録の「配信種別」）
 *   ② ARENA場所貸し … projects.arena_options['broadcast'] ＝「あり／なし」（IKUSA側で対応する7項目のひとつ）
 *   画面ごとに片方だけ見ると、**ARENAの配信案件が一覧から丸ごと抜け落ちる**。
 *   baba決定（2026-09-18）＝**両方出す**。だから判定はここ1か所にまとめる。
 *
 * ⚠ SQLで JSON（arena_options）を絞るのは環境で書き方が変わる（SQLite / MySQL）。
 *   ざっくり候補を引いてから、この判定でふるいにかけること（→ candidates()）。
 */
final class BroadcastKind
{
    /** 通常の案件で「配信あり」と数える値（正本）。⚠ 増やすときはここに1行。 */
    public const ON_VALUES = ['配信', '中継'];

    /** ARENA場所貸しの「配信・中継」が有のときの値。 */
    public const ARENA_ON = 'あり';

    /** 配信・中継のある案件か。 */
    public static function isOn(Project $project): bool
    {
        return self::label($project) !== '';
    }

    /**
     * 画面に出す種別。「配信」「中継」「配信・中継」（ARENA）。無ければ空文字。
     * ⚠ 文言はここが正本（画面ごとに書かない）。
     */
    public static function label(Project $project): string
    {
        $kind = trim((string) ($project->broadcast ?? ''));
        if (in_array($kind, self::ON_VALUES, true)) {
            return $kind;
        }

        $arena = $project->arena_options;
        if (is_array($arena) && trim((string) ($arena['broadcast'] ?? '')) === self::ARENA_ON) {
            return '配信・中継';
        }

        return '';
    }

    /** ARENA場所貸しの側で「あり」になっている案件か（一覧で出どころを添えるのに使う）。 */
    public static function isArena(Project $project): bool
    {
        $kind = trim((string) ($project->broadcast ?? ''));
        if (in_array($kind, self::ON_VALUES, true)) {
            return false;
        }

        $arena = $project->arena_options;

        return is_array($arena) && trim((string) ($arena['broadcast'] ?? '')) === self::ARENA_ON;
    }

    /**
     * 候補を広めに引くための絞り込み。
     * ⚠ ARENA は JSON の中を見ないと分からないので、ここでは落とさずに通す。
     *   最後のふるいは isOn() で行う（SQLでJSONを触らない＝環境差で壊れないようにする）。
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Project>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Project>
     */
    public static function candidates($query)
    {
        return $query->where(function ($q) {
            $q->whereIn('broadcast', self::ON_VALUES)
                ->orWhere('format', 'like', '%ARENA%');
        });
    }
}
