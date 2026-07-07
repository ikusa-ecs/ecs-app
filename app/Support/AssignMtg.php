<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * アサインMTG日の予定表（共通設定）。
 *
 * settings テーブルの key='assign_mtg_dates' に「YYYY-MM-DD の配列」をJSONで保存する。
 * 「追加案件」の自動判定に使う“基準日”＝今日までで一番新しいMTG日を、ここから都度計算する。
 *   （毎月手で1つの日付を更新しなくても、予定をまとめて登録しておけば自動で切り替わる。）
 *
 * ※旧仕様（key='assign_mtg_date' に単一の日付）から移行しても読めるよう、
 *   リストが空なら旧キーの1件を拾う（後方互換）。
 */
class AssignMtg
{
    /** 登録済みのMTG日一覧（YYYY-MM-DD文字列・昇順・重複なし）。 */
    public static function dates(): array
    {
        $raw = Setting::get('assign_mtg_dates');
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($arr) || ! $arr) {
            // 後方互換：旧・単一キーがあればそれを1件のリストとして扱う。
            $legacy = Setting::get('assign_mtg_date');

            return $legacy ? [$legacy] : [];
        }

        $arr = array_values(array_unique(array_filter(array_map('trim', $arr))));
        sort($arr);

        return $arr;
    }

    /** MTG日一覧を保存する（重複除去・昇順に整える）。保存後の配列を返す。 */
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

        Setting::put('assign_mtg_dates', json_encode($list));

        return $list;
    }

    /**
     * 「追加案件」自動判定の基準日＝今日までで一番新しいMTG日（過ぎた中で最新）。
     * まだ過ぎたMTGが無い／未登録なら null（＝自動判定しない）。
     */
    public static function current(?string $today = null): ?string
    {
        $today = $today ?: Carbon::now()->format('Y-m-d');
        $past = array_filter(self::dates(), fn ($d) => $d <= $today);

        return $past ? end($past) : null;
    }
}
