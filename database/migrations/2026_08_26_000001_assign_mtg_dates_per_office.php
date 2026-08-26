<?php

use App\Models\Office;
use App\Models\Setting;
use App\Support\AssignMtg;
use App\Support\OfficeScope;
use App\Support\OfficeSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * アサインMTG日の予定表を、拠点ごとに持てるようにする（2026-08-26 baba要望）。
 *
 * ⚠ 全体アサインMTGの日は拠点ごとに違うのに、全国で1つしか持てなかった。
 *   そのため東京のMTG日を基準に、東北の案件まで「追加案件」と判定されていた。
 *
 * ここでやること＝**今の値を、いまある拠点すべてにコピーする**。
 * こうすると切り替えた瞬間にどの拠点でも基準日が消えない（そのあとは拠点ごとに直せる）。
 * コピーし終わったら、全国共通だった行は消す。
 * ※ お知らせ文・締切日を拠点ごとにしたとき（2026_08_25_000002）と同じ手順。
 *
 * ※ 危険日にはこの作業が要らない。全拠点共通の危険日は**今までのキーをそのまま使う**ので、
 *   もともと登録してあった危険日は自動的に「全拠点の危険日」になる。
 */
return new class extends Migration
{
    /** 全国共通だった昔のキー（新しい方＝複数登録・古い方＝単一の日付）。 */
    private const OLD_KEYS = ['assign_mtg_dates', 'assign_mtg_date'];

    public function up(): void
    {
        $offices = self::offices();

        foreach (self::OLD_KEYS as $oldKey) {
            $old = Setting::get($oldKey, null);
            if ($old === null || trim((string) $old) === '') {
                continue;   // もともと未設定＝コピーするものが無い
            }

            foreach ($offices as $office) {
                // すでに拠点ごとの値があるなら触らない（入れ直しても壊れないように）。
                if (Setting::get(OfficeSettings::key(AssignMtg::KEY, $office), null) === null) {
                    Setting::put(OfficeSettings::key(AssignMtg::KEY, $office), (string) $old);
                }
            }
        }

        foreach (self::OLD_KEYS as $oldKey) {
            Setting::where('key', $oldKey)->delete();
        }
    }

    public function down(): void
    {
        // 元に戻すときは、既定の拠点（東京）の値を全国共通の値として戻す。
        $value = Setting::get(OfficeSettings::key(AssignMtg::KEY, OfficeScope::DEFAULT_OFFICE), null);
        if ($value !== null) {
            Setting::put(AssignMtg::KEY, (string) $value);
        }
        Setting::where('key', 'like', AssignMtg::KEY.':%')->delete();
    }

    /** いま登録されている拠点（1つも無ければ既定の拠点だけ）。 */
    private static function offices(): array
    {
        $names = Office::pluck('name')->filter()->values()->all();

        return $names ?: [OfficeScope::DEFAULT_OFFICE];
    }
};
