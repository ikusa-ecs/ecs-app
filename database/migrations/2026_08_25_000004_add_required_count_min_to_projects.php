<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 運営人数を「6〜8人」のように おおよそ で入れられるようにする（2026-08-25 baba要望）。
 *
 * ・required_count      … これまでどおり「計算に使う数字」＝範囲の**多いほう**。
 * ・required_count_min  … 範囲の**少ないほう**。範囲でないときは null（今までの案件は全部 null）。
 *
 * ⚠ 計算（残り○名・必要○名）を作り替えないための持ち方。多いほうを今までの列に入れておけば、
 *   「8人埋まって初めて満員」（baba決定）が、既存の計算をそのまま使って成り立つ。
 *   読み書きの決まりは App\Support\Headcount が正本。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('required_count_min')->nullable()->after('required_count');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('required_count_min');
        });
    }
};
