<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 新人の研修先（月ごと）＝ {"2026-12": "東京"}（2026-11-11 小沼さん：福岡に12月入社の人が12月は東京で研修）。
 * 名簿の拠点は変えずに、その月だけ研修先の拠点の新人ページにも出す。正本＝App\Support\Rookies::trainingOffice。
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
