<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SF商品の対応表に「あそ研も一緒に付ける」の印を足す（2026-11-18 小沼さん決定）。
 *
 * SFには「NEW運動会（あそ研）」のように、同じコンテンツの「あそ研つき」の商品が別にある。
 * ECSでは「NEW運動会（あそ研）」という別のコンテンツは作らず、
 * 「NEW運動会」と「あそ研」の2つを付けた案件にする（アサイン表の「〇〇＋あそ研」と同じ）。
 * 対応表は1商品に1コンテンツなので、もう1つ付けるかどうかをこの列で覚える。
 *
 * すでに入っている商品は、商品ファミリが「あそ研」のものだけ はじめから印を付ける（人が外せる）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sf_products', function (Blueprint $table) {
            $table->boolean('with_asoken')->default(false)->after('content_id');
        });

        DB::table('sf_products')->where('family', 'あそ研')->update(['with_asoken' => true]);
    }

    public function down(): void
    {
        Schema::table('sf_products', function (Blueprint $table) {
            $table->dropColumn('with_asoken');
        });
    }
};
