<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アサインに「備考（その人の担当）」を追加。例：軍師／ディーラー／巡回：リアル 等。
 * 必要ポジション枠の note に対応させ、誰がどの担当かを持てるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('note')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
