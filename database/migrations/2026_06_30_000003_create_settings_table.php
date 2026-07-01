<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * アプリ全体の小さな設定を入れる key/value テーブル。
 * 最初の用途＝スタッフ画面のお知らせ文（key='staff_notice'）。
 * 1行＝1設定。今後の単純な設定（既定値など）もここに足せる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
