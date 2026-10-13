<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 手当の記録に「自動で入れた行」を持てるようにする（2026-10-13 baba
 * 「前日設営・リハの手当は手当の記録に自動で入るように。でも削除できるように」）。
 *   auto_key     … どのアサインから自動で入れたか（同じ行を二度入れないための鍵）。手で入れた行は空。
 *   dismissed_at … 自動の行を「消した」印。消した行は画面に出さず、次に開いても入れ直さない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_allowances', function (Blueprint $table) {
            if (! Schema::hasColumn('staff_allowances', 'auto_key')) {
                $table->string('auto_key', 160)->nullable()->index();
            }
            if (! Schema::hasColumn('staff_allowances', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_allowances', function (Blueprint $table) {
            foreach (['auto_key', 'dismissed_at'] as $col) {
                if (Schema::hasColumn('staff_allowances', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
