<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_relations（NGペア）。設計書8章。
 * 同じ現場を避けたいスタッフの組合せ。相手はまだ未登録の人もいるため、
 * 氏名（partner_name）で持ち、登録済みなら partner_id（people.id）も入れる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_relations', function (Blueprint $table) {
            $table->id();
            $table->string('staff_id');                    // people.id（本人）
            $table->string('partner_name');                // NG相手の氏名
            $table->string('partner_id')->nullable();      // 登録済みなら people.id
            $table->string('relation_type')->default('NG');// 種別（今は NG のみ）
            $table->timestamps();

            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_relations');
    }
};
