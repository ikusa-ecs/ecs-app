<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月まとめの自動アサイン「1回ぶん」の記録（2026-09-07 baba要望）。
 *
 * 【なぜ要るか】
 * 1か月ぶんをまとめて入れる操作は、**取り返しがつきにくい**。
 * 「やっぱり元に戻したい」ができないと、怖くて誰も押せない。
 * そこで **1回の実行に番号を振り**、入れたアサインにその番号を持たせて、
 * あとから「この回のぶんだけ」消せるようにする。
 *
 * ⚠ 取り消すのは **その回に入れた「仮」のまま** のものだけ（消し方は Controller 側）。
 *   人が「確定」に上げたもの・手で直したものは残す＝人の判断を機械が消さない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_assign_runs', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->index();          // 対象月 '2026-09'
            $table->string('office', 20)->nullable();      // 実行時に見ていた拠点（null＝全拠点）
            $table->string('run_by', 40)->nullable();      // 実行した人（people.id）
            $table->unsignedInteger('added')->default(0);  // その回に入れた人数
            $table->unsignedInteger('undone')->default(0); // 取り消した人数（0＝まだ取り消していない）
            $table->timestamps();
        });

        Schema::table('assignments', function (Blueprint $table) {
            // ⚠ nullable。手で入れたアサインには番号が付かない（＝まとめて消す対象にならない）。
            $table->unsignedBigInteger('auto_run_id')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('auto_run_id');
        });
        Schema::dropIfExists('auto_assign_runs');
    }
};
