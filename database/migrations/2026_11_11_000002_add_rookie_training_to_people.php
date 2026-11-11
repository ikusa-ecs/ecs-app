<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人の研修先（期間）＝ [{"from":"2026-12-01","to":"2026-12-15","office":"東京"}]（2026-11-11 小沼さん：福岡に12月入社の人が東京で研修）。
 * 名簿の拠点は変えずに、期間中だけ研修先の拠点の新人ページにも出す。正本＝App\Support\Rookies::trainingPeriods。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->json('rookie_training')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('rookie_training');
        });
    }
};
