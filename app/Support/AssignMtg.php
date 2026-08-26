<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * アサインMTG日の予定表（共通設定）。
 *
 * settings テーブルに「YYYY-MM-DD の配列」をJSONで保存する。
 * 「追加案件」の自動判定に使う“基準日”＝今日までで一番新しいMTG日を、ここから都度計算する。
 *   （毎月手で1つの日付を更新しなくても、予定をまとめて登録しておけば自動で切り替わる。）
 *
 * 【拠点ごとに持つ（2026-08-26 baba要望）】
 * ⚠ 全体アサインMTGの日は**拠点ごとに違う**。全国で1つしか持てなかったため、東京のMTG日で
 *   東北の案件まで「追加案件」と判定されていた。
 *   持ち方は お知らせ文・締切日と同じ＝キーを「項目名:拠点名」にするだけ（`assign_mtg_dates:東京`）。
 *   正本のキー組み立ては OfficeSettings::key に寄せる（同じ決まりを2か所に書かない）。
 *
 * 【昔の値も読める】
 *  ① その拠点の行 → ② 全国共通だった行（`assign_mtg_dates`） → ③ 旧・単一キー（`assign_mtg_date`）
 *  の順に探す。切り替えの瞬間に基準日が消えないようにするため。
 */
class AssignMtg
{
    /** settings のキー名（拠点名は OfficeSettings::key が付ける）。 */
    public const KEY = 'assign_mtg_dates';

    /** 全国共通だった昔のキー（読み取りだけ・後方互換）。 */
    private const LEGACY_KEYS = ['assign_mtg_dates', 'assign_mtg_date'];

    /**
     * 登録済みのMTG日一覧（YYYY-MM-DD文字列・昇順・重複なし）。
     *
     * @param  string|null  $office  拠点（空・null は既定の拠点＝東京あつかい）
     */
    public static function dates(?string $office = null): array
    {
        $list = self::read(OfficeSettings::key(self::KEY, $office));
        if ($list !== []) {
            return $list;
        }

        // その拠点にまだ無ければ、全国共通だった昔の値を読む（移行前でも基準日が消えない）。
        foreach (self::LEGACY_KEYS as $key) {
            $list = self::read($key);
            if ($list !== []) {
                return $list;
            }
        }

        return [];
    }

    /** MTG日一覧を保存する（重複除去・昇順に整える）。保存後の配列を返す。 */
    public static function save(array $dates, ?string $office = null): array
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

        Setting::put(OfficeSettings::key(self::KEY, $office), json_encode($list));

        return $list;
    }

    /**
     * 「追加案件」自動判定の基準日＝今日までで一番新しいMTG日（過ぎた中で最新）。
     * まだ過ぎたMTGが無い／未登録なら null（＝自動判定しない）。
     */
    public static function current(?string $today = null, ?string $office = null): ?string
    {
        $today = $today ?: Carbon::now()->format('Y-m-d');
        $past = array_filter(self::dates($office), fn ($d) => $d <= $today);

        return $past ? end($past) : null;
    }

    /**
     * 拠点 → 基準日 の対応。
     * ⚠ 案件登録の画面は「登録拠点」を途中で変えられるので、拠点ごとの基準日をまとめて渡す
     *   （1つだけ渡すと、拠点を変えても追加案件の判定が変わらない）。
     *
     * @param  list<string>  $offices
     * @return array<string, string|null>
     */
    public static function currentByOffice(array $offices, ?string $today = null): array
    {
        $map = [];
        foreach ($offices as $office) {
            $map[$office] = self::current($today, $office);
        }

        return $map;
    }

    /** そのキーに入っている日付の配列（整えたもの）。 */
    private static function read(string $key): array
    {
        $raw = Setting::get($key);
        $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);

        // 旧・単一キーは日付そのもの（配列ではない）が入っている。
        if (! is_array($arr)) {
            $one = trim((string) $raw);

            return $one !== '' ? [$one] : [];
        }

        $arr = array_values(array_unique(array_filter(array_map('trim', $arr))));
        sort($arr);

        return $arr;
    }
}
