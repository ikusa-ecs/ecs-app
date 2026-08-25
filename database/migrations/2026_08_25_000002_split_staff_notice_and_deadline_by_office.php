<?php

use App\Models\Office;
use App\Models\Setting;
use App\Support\OfficeScope;
use App\Support\OfficeSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * 「スタッフ画面のお知らせ文」と「通常案件の一斉締切日」を、拠点ごとに持てるようにする。
 * 2026-08-25 baba要望：全国共通だったため、東京で直すと東北のスタッフ画面まで変わっていた。
 *
 * ここでやること＝**今の値を、いまある拠点すべてにコピーする**。
 * こうすると、切り替えた瞬間にどの拠点の画面からも文が消えない
 * （そのあとは拠点ごとに直せる）。コピーし終わったら、全国共通だった行は消す。
 */
return new class extends Migration
{
    public function up(): void
    {
        $offices = self::offices();

        foreach ([OfficeSettings::NOTICE, OfficeSettings::DEADLINE] as $name) {
            $old = Setting::get($name, null);
            if ($old === null) {
                continue;   // もともと未設定＝コピーするものが無い
            }

            foreach ($offices as $office) {
                // すでに拠点ごとの値があるなら触らない（入れ直しても壊れないように）。
                if (Setting::get(OfficeSettings::key($name, $office), null) === null) {
                    OfficeSettings::put($name, $office, (string) $old);
                }
            }

            Setting::where('key', $name)->delete();
        }
    }

    public function down(): void
    {
        // 元に戻すときは、既定の拠点（東京）の値を全国共通の値として戻す。
        foreach ([OfficeSettings::NOTICE, OfficeSettings::DEADLINE] as $name) {
            $value = Setting::get(OfficeSettings::key($name, OfficeScope::DEFAULT_OFFICE), null);
            if ($value !== null) {
                Setting::put($name, (string) $value);
            }
            Setting::where('key', 'like', $name.':%')->delete();
        }
    }

    /** いま登録されている拠点（1つも無ければ既定の拠点だけ）。 */
    private static function offices(): array
    {
        $names = Office::pluck('name')->filter()->values()->all();

        return $names ?: [OfficeScope::DEFAULT_OFFICE];
    }
};
