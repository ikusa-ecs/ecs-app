<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「MCとして入れる、いちばん大きい規模」（2026-09-09 baba要望）。
 *
 * 【なぜ要るか】baba「このMCさんは最近MCオーディション合格したから
 *   少人数の案件でMCにしたい」＝MCができる／できないの2つだけでは足りない。
 *
 * 空（null）＝制限なし＝今までどおり（どの規模のMCにも入れる）。
 * 値の決まり・判定は App\Support\RoleScale が正本。
 *
 * ⚠ OP の op_online / op_real と同じ作法（people の列）にそろえている。
 *   ほかの役割にも広げるときは、列を増やさず staff_role_eligibility 側に移すこと。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('mc_max_scale', 10)->nullable()->after('op_real');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('mc_max_scale');
        });
    }
};
