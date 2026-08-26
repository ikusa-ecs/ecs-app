<?php

namespace App\Support;

use App\Models\Setting;

/**
 * 危険日（手動指定）の共通設定。
 *
 * ダッシュボードの危険日カレンダーは、自動判定（大型2件以上 等）に加えて、
 * ここで手動指定した日も危険日として赤く表示する。
 * （大型1件でも実際は危険・全拠点合算で危険、といった自動では拾えないケースを人が足せるように。）
 *
 * 【2通りの持ち方（2026-08-26 baba要望）】
 *  ・**全拠点**（共通）… どの拠点の画面にも出る。key='manual_danger_dates'
 *    ＝**今までのキーそのまま**。だから今まで登録してあった危険日は、そのまま「全拠点」になる
 *    （移行の作業が要らず、切り替えで危険日が消えない）。
 *  ・**その拠点だけ** … その拠点の画面にだけ出る。key='manual_danger_dates:東京'
 *
 * 画面に出す危険日は、いつも「**全拠点 ＋ その拠点**」を合わせたもの。
 * 拠点をまたいで見るとき（全拠点表示）は、どこかの拠点で危険日ならまとめて出す。
 */
class DangerDays
{
    /** settings のキー名（拠点名を付けない＝全拠点共通）。 */
    public const KEY = 'manual_danger_dates';

    /** 全拠点共通の危険日（どの拠点にも出る）。 */
    public static function allOfficesDates(): array
    {
        return self::read(self::KEY);
    }

    /** その拠点だけの危険日。 */
    public static function officeDates(?string $office): array
    {
        return self::read(OfficeSettings::key(self::KEY, $office));
    }

    /**
     * 画面に出す危険日＝全拠点共通 ＋ その拠点。
     *
     * @param  string|null  $office  null＝拠点で絞らない（全拠点共通＋どの拠点の分も全部）
     */
    public static function dates(?string $office = null): array
    {
        $lists = [self::allOfficesDates()];

        if ($office === null) {
            // 拠点で絞らないときは、どこかの拠点で危険日ならまとめて出す。
            foreach (Setting::where('key', 'like', self::KEY.':%')->pluck('key') as $key) {
                $lists[] = self::read($key);
            }
        } else {
            $lists[] = self::officeDates($office);
        }

        return self::tidy(array_merge(...$lists));
    }

    /** 全拠点共通の危険日を保存する。保存後の配列を返す。 */
    public static function saveAllOffices(array $dates): array
    {
        $list = self::tidy($dates);
        Setting::put(self::KEY, json_encode($list));

        return $list;
    }

    /** その拠点だけの危険日を保存する。保存後の配列を返す。 */
    public static function saveOffice(array $dates, ?string $office): array
    {
        $list = self::tidy($dates);
        Setting::put(OfficeSettings::key(self::KEY, $office), json_encode($list));

        return $list;
    }

    /** そのキーに入っている日付の配列（整えたもの）。 */
    private static function read(string $key): array
    {
        $raw = Setting::get($key);
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($arr) ? self::tidy($arr) : [];
    }

    /** 空を捨て、重複を除き、昇順にそろえる。 */
    private static function tidy(array $dates): array
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

        return $list;
    }
}
