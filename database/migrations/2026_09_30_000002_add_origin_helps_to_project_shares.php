<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 巻き取りの案件に「登録した拠点からも人を出す」印（2026-09-30 baba要望）。
 *
 * 【なぜ】他拠点に巻き取ってもらったが、その拠点だけでは人が足りず、登録した拠点からもスタッフを出すことがある。
 *   巻き取られた案件は登録拠点の日別ボードから外している（OfficeScope::hideTakenOver・2026-09-16）ので、
 *   「あと◯名」を見ながら自拠点のスタッフを詰められなかった。
 * 【どう効くか】印が付いた巻き取りだけ、登録拠点の日別ボードにも出す。
 *   ⚠ スタッフ公開ボードには出さない（公開・非公開は運営する拠点＝引き取った側が決める）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_shares', function (Blueprint $table) {
            $table->boolean('origin_helps')->default(false)->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('project_shares', function (Blueprint $table) {
            $table->dropColumn('origin_helps');
        });
    }
};
