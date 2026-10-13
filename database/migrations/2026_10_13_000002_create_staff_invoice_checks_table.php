<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スタッフ別アサイン（請求書チェック）の「📨請求書が届いた」「✅チェックした」の印（2026-10-13 baba）。
 * それまではアサイン表の人数の隣に四角を書いて、手で埋めてチェックしていた。
 * 1行＝1人×1か月。誰がいつ付けたかも残す（あとで「チェックしたのは誰？」に答えるため）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_invoice_checks')) {
            return;
        }
        Schema::create('staff_invoice_checks', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id', 40);
            $table->string('ym', 7);                       // 例 2026-10
            $table->timestamp('received_at')->nullable();  // 📨 請求書が届いた
            $table->string('received_by', 100)->nullable();
            $table->timestamp('checked_at')->nullable();   // ✅ 中身をチェックした
            $table->string('checked_by', 100)->nullable();
            $table->timestamps();
            $table->unique(['staff_id', 'ym']);
            $table->index('ym');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invoice_checks');
    }
};
