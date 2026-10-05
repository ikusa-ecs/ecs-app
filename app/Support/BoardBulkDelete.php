<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 日別ボードの「🗑 まとめて削除」を出すかどうか（2026-10-05 baba要望）。
 *
 * 【なぜあるか】
 * アサイン表の取込でIDがずれた関係で、同じ案件がダブって登録されている。
 * 1件ずつ消すのは手間なので、日別ボードで選んだものをまとめて消せるようにした。
 * ⚠ 一時的なもの＝作業が終わったら共通設定のスイッチで隠す（コードは消さない＝また重複が出たら出し直せる）。
 *
 * 【決めたこと（baba）】
 *   ・使えるのは **Administrator だけ**（消すと入っている人も一緒に消え、元に戻せないため）。
 *   ・スイッチは共通設定（POST /settings/board-bulk-delete）。既定は「隠す」。
 *   ・消す前に、入っている人・エントリーの件数を見せて確認する（ProjectController::bulkDestroy）。
 */
final class BoardBulkDelete
{
    public const KEY = 'board_bulk_delete_enabled';

    /** スイッチが入っているか。 */
    public static function enabled(): bool
    {
        return (string) Setting::get(self::KEY, '') === '1';
    }

    public static function setEnabled(bool $on): void
    {
        Setting::put(self::KEY, $on ? '1' : '');
    }

    /** この人の日別ボードにボタンを出すか＝スイッチが入っていて、Administrator のとき。 */
    public static function visibleFor($user): bool
    {
        return self::enabled() && (($user->permission ?? '') === 'admin');
    }
}
