<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサインに「兼任（サブ役割）」role2 を追加。
 * 1人が2役をこなす場合（D兼OP・OP兼FC など）に、主役割 role とは別にもう1つの役割を持てるようにする。
 * ポジション枠は role と role2 の両方に +1（1人で2枠カバー）で数える。人数（体）は1人のまま。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('role2')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('role2');
        });
    }
};
