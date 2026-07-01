<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 拠点（事務所）マスタ。
 *
 * これまで拠点は people.office に文字列で入っているだけで、選択肢の「元データ」が無かった。
 * 設定画面のマスタ管理から追加・編集できるよう、拠点の一覧をここで持つ。
 * people.office は従来どおり事務所名の文字列を保持し、このマスタが選択肢の元になる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();     // 事務所名（例：東京）
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // 現在 people.office で使われている6拠点を初期投入。
        $now = now();
        $offices = ['東京', '大阪', '名古屋', '福岡', '東北', '北海道'];
        $rows = [];
        foreach ($offices as $i => $name) {
            $rows[] = [
                'name' => $name,
                'sort_order' => ($i + 1) * 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('offices')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('offices');
    }
};
