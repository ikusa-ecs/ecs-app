<?php

namespace App\Support;

use App\Models\Project;

/**
 * 「この案件をイベント数として数えるか」を決める1か所（先人の要件定義 先-2）。
 *
 * ■ なぜ必要か
 *   集計ダッシュボード（/stats）は開催日のある案件を全部数えていたため、社内で言う
 *   「イベント数」とズレていた。社内の数え方＝**体験会・EXPO は数えない**。
 *
 * ■ 決め方（案件の count_as_event 列）
 *   null  … 自動（下のルールで決める。既定）
 *   true  … 必ず数える（自動で外れる案件でも数えたいとき）
 *   false … 必ず数えない
 *
 * ■ 自動のルール
 *   ・実施形態が EXCLUDED_FORMATS に入っている（＝体験会）→ 数えない
 *   ・案件名・クライアント名に EXCLUDED_WORDS の語が入っている（＝EXPO）→ 数えない
 *     ※ EXPO は入力欄（実施形態など）に無いため、名前で拾うしかない。
 *       間違って外れたら、その案件で「数える」を選べば上書きできる。
 *   ・それ以外 → 数える
 *
 * ■ 増やすとき
 *   数えない形態・語を増やすのはこのクラスの定数に1行足すだけ。**画面や集計側に書かない。**
 */
class EventCount
{
    /** 自動で「数えない」ことにする実施形態（projects.format）。 */
    public const EXCLUDED_FORMATS = ['体験会'];

    /** 自動で「数えない」ことにする語（案件名・クライアント名に含まれていたら対象）。 */
    public const EXCLUDED_WORDS = ['EXPO', 'エキスポ'];

    /** この案件はイベント数として数えるか（手動指定があればそれが優先）。 */
    public static function counts(Project $project): bool
    {
        if ($project->count_as_event !== null) {
            return (bool) $project->count_as_event;
        }

        return self::autoCounts($project);
    }

    /** 自動ルールだけで判定した結果（手動指定を見ない）。画面の案内文にも使う。 */
    public static function autoCounts(Project $project): bool
    {
        return self::autoReason($project) === null;
    }

    /**
     * 自動で「数えない」と判断した理由。数える場合は null。
     * 画面で「この案件は自動だと数えません（体験会のため）」と出すために使う。
     */
    public static function autoReason(Project $project): ?string
    {
        // キャンセルになった案件は数えない（2026-08-26）。
        // 実施していないので「イベント数」に入れてはいけない。
        if ($project->is_cancelled) {
            return 'キャンセルのため';
        }

        $format = trim((string) ($project->format ?? ''));
        if (in_array($format, self::EXCLUDED_FORMATS, true)) {
            return $format . 'のため';
        }

        $haystack = mb_strtoupper((string) $project->project_name . ' ' . (string) $project->client);
        foreach (self::EXCLUDED_WORDS as $word) {
            if ($word !== '' && mb_strpos($haystack, mb_strtoupper($word)) !== false) {
                return $word . 'を含むため';
            }
        }

        return null;
    }

    /**
     * 手動指定・自動判定をまとめた「いまの状態」の言い方（画面や履歴の表示用）。
     * 例：'数える（自動）' / '数えない（自動・体験会のため）' / '数えない（手動）'
     */
    public static function label(Project $project): string
    {
        if ($project->count_as_event !== null) {
            return ($project->count_as_event ? '数える' : '数えない') . '（手動）';
        }

        $reason = self::autoReason($project);

        return $reason === null ? '数える（自動）' : '数えない（自動・' . $reason . '）';
    }
}
