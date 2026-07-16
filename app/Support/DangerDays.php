<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 危険日（手動指定）の共通設定。
 *
 * settings テーブルの key='manual_danger_dates' に「YYYY-MM-DD の配列」をJSONで保存する。
 * ダッシュボードの危険日カレンダーは、自動判定（大型2件以上 等）に加えて、
 * ここで手動指定した日も危険日として赤く表示する。
 * （大型1件でも実際は危険・全拠点合算で危険、といった自動では拾えないケースを人が足せるように。）
 */
class DangerDays
{
    /** 手動指定の危険日一覧（YYYY-MM-DD文字列・昇順・重複なし）。 */
    public static function dates(): array
    {
        $raw = Setting::get('manual_danger_dates');
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($arr)) {
            return [];
        }

        $arr = array_values(array_unique(array_filter(array_map('trim', $arr))));
        sort($arr);

        return $arr;
    }

    /** 危険日一覧を保存する（重複除去・昇順に整える）。保存後の配列を返す。 */
    public static function save(array $dates): array
    {
        $clean = [];
        foreach ($dates as $d) {
            $d = trim((string) $d);
            if ($d !== '') {
                $clean[$d] = true;   // キーにして重複を自然に除去
            }
        }
        $list = array_keys($clean);
        sort($list);

        Setting::put('manual_danger_dates', json_encode($list));

        return $list;
    }
}
