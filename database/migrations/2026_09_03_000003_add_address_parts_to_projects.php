<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の会場住所から切り出した「都道府県」と「市区町村」（2026-09-03）。
 *
 * 【なぜ要るか】
 * ⚠ 会場は `projects.location` に住所が**丸ごと1本**で入っているだけなので、
 *   「千葉県流山市の近くの案件」のように**場所で機械的に探せなかった**。
 *   体験の受け入れ先を探すとき（/taiken-search）に、県と市区町村で比べたい。
 *
 * ⚠ location は消さない・変えない。ここは**location から切り出した写し**。
 *   正本は今までどおり location（自由記述）で、切り出しは App\Support\AddressParts。
 * ⚠ 都道府県が書かれていない住所（「渋谷区…」で始まるものなど）は**空のまま**にする。
 *   推測で「東京都」を入れると、あとで間違いに気づけない。
 *
 * 既存の案件をまとめて埋めるコマンド＝`php artisan ecs:fill-project-address`。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('prefecture', 10)->nullable()->index()->after('location');
            $table->string('city', 50)->nullable()->index()->after('prefecture');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['prefecture', 'city']);
        });
    }
};
