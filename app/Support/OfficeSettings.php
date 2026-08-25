<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 拠点ごとの小さな設定（お知らせ文・通常案件の締切日）の正本（2026-08-25 baba要望）。
 *
 * 【なぜ要るか】
 * これまで「スタッフ画面のお知らせ文」と「通常案件の一斉締切日」は
 * **全国で1つ**しか持てなかった。拠点ごとに募集の出し方も締切も違うので、
 * 東京で直すと東北のスタッフの画面まで変わってしまっていた。
 *
 * 【持ち方】
 * settings テーブルのキーを「項目名:拠点名」にするだけ（例：`staff_notice:東京`）。
 * 新しいテーブルを作らないのは、値が1拠点につき1つの短い文字列だけだから。
 *
 * ⚠ 拠点が空（''）で呼ばれたときは既定の拠点（東京）として扱う。
 *   人の office が未入力でも、どこかの拠点の設定に必ず結びつくようにするため。
 */
final class OfficeSettings
{
    /** スタッフ画面（募集タブ）の一番上に出るお知らせ文。 */
    public const NOTICE = 'staff_notice';

    /** 通常案件の「一斉の締切日」（Y-m-d／空＝未設定）。 */
    public const DEADLINE = 'entry_deadline';

    /** その拠点の設定値を取り出す（未設定は空文字）。 */
    public static function get(string $name, ?string $office): string
    {
        return trim((string) Setting::get(self::key($name, $office), ''));
    }

    /** その拠点の設定値を保存する（空＝未設定に戻す）。 */
    public static function put(string $name, ?string $office, string $value): void
    {
        Setting::put(self::key($name, $office), trim($value));
    }

    /** settings のキー。「項目名:拠点名」。 */
    public static function key(string $name, ?string $office): string
    {
        $o = trim((string) $office);

        return $name.':'.($o !== '' ? $o : OfficeScope::DEFAULT_OFFICE);
    }
}
