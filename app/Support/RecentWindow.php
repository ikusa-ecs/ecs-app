<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 「ふだんの画面で読む案件の範囲」の正本（2026-10-09 baba決定＝**3か月より前は読まない**）。
 *
 * 【なぜ】過去のアサイン表（2023年〜・約2,500件）を取り込むと、案件一覧・公開ボード・
 *   日別ボードなど約20画面が「全部の案件を毎回読む」作りのため重くなる。
 *   3か月より前の案件は「🗂 過去案件」（/past-projects）で月ごとに見る。
 *
 * ⚠ 外してはいけない画面（過去を数えるのが仕事のもの）には使わない：
 *   集計ダッシュボード・経験回数・新人・クライアント別アサイン履歴・名簿の稼働状況・
 *   スタッフ本人の履歴・マイページ・収支一覧・過去案件・取込の照合。
 * ⚠ 開催日が空の案件は残す（日付未定＝これからの案件なので）。
 * ⚠ 画面ごとに「3か月」を書かない。変えるときはここ1か所。
 */
final class RecentWindow
{
    /** 何か月前までを、ふだんの画面で読むか。 */
    public const MONTHS = 3;

    /** これより前の開催日は読まない（この日は含む）。 */
    public static function from(): Carbon
    {
        return Carbon::today()->subMonths(self::MONTHS);
    }

    /**
     * 案件のクエリを「3か月より前を外す」形に絞る。開催日が空の案件は残す。
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function apply($query, string $column = 'start_date')
    {
        $from = self::from()->format('Y-m-d');

        return $query->where(function ($q) use ($column, $from) {
            $q->whereNull($column)->orWhereDate($column, '>=', $from);
        });
    }
}
