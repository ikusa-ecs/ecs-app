<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサインに「備考（一言）」を追加。
 * note（担当＝軍師／サポ等の役割メモ）や patrol（巡回数）とは別に、
 * 「この人は昼から」「初参加なのでフォロー」など、人ごとの自由な一言を残せるようにする。
 * アサインする全画面（アサイン画面・日別ボード・ピックアップ）で共通に使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('remark')->nullable()->after('patrol');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
};
