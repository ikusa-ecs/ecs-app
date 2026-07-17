<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OP（音響）を「オンライン可／リアル可」で区別する属性（B案）。
 * OP の役割コードは1つのまま（staff_role_eligibility は従来どおり position='OP'）、
 * スタッフごとに「オンラインのOPができる／リアル(現地)のOPができる」を別フラグで持つ。
 * どちらも null＝未設定（不明）＝従来どおり「OP」とだけ表示する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('op_online')->nullable()->after('is_exclusive');
            $table->boolean('op_real')->nullable()->after('op_online');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['op_online', 'op_real']);
        });
    }
};
