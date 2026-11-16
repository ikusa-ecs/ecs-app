<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salesforce（SF）の「商品」と ECS のコンテンツの対応表（2026-11-16）。
 *
 * 【なぜ要るか】SFではコンテンツを「商品（Product2）」で管理していて、名前がECSのコンテンツ名と違う。
 *   さらに商品にはコンテンツ以外（オプション・機材・割引など）も混ざっている。
 *   そこで「この商品はECSのどのコンテンツか／無視してよいか」を1件ずつ人が決めて、ここに覚えておく。
 *
 * ⚠ 鍵は商品Id（18桁）。名前は表示用。
 *   末尾の（ファミリー名）を外すと62件が同じ名前になるため、名前を鍵にしない（調査結果 D-3）。
 * ⚠ 扱い（handling）：pending＝未対応／content＝コンテンツ／ignore＝無視。
 *   未対応の商品は受信箱で「未対応」として目立たせる（黙って落とさない）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sf_products', function (Blueprint $table) {
            $table->id();
            $table->string('sf_product_id', 18)->unique();          // SFの商品Id（18桁）＝鍵
            $table->string('name');                                 // SFの商品名（括弧つきフルネーム・表示用）
            $table->string('family')->nullable();                   // 商品ファミリ（あそ研・ビジパ など）
            $table->boolean('is_active')->default(true);            // SFで有効か
            $table->string('handling', 16)->default('pending');     // pending=未対応 / content=コンテンツ / ignore=無視
            $table->string('content_id')->nullable();               // ECSのコンテンツID（contents.id）。handling=content のときだけ
            $table->string('hint')->nullable();                     // CSV最終列の書き込み（「△機材の可能性」など）＝無視の候補。確定ではない
            $table->text('note')->nullable();                       // 人のメモ（取込し直しても消さない）
            $table->string('updated_by')->nullable();               // 最後に扱いを決めた人（people.id）
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sf_products');
    }
};
