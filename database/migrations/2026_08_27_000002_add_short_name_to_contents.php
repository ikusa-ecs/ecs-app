<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツ台帳に「略称」を追加（2026-08-27 baba要望）。
 *
 * 【なぜ台帳に持つか】
 * カレンダーの予定名では正式名ではなく略称を使う運用がある
 * （例「先が見えない防災訓練」→「防災訓練」）。
 * ⚠ カレンダー専用の変換表を別に作ると台帳と二重管理になり、
 *   コンテンツが増えたときに片方だけ直して食い違う。台帳に1列足すのが正しい持ち方。
 *   略称は今後アサイン表・チャットワーク通知でも使える。
 *
 * ⚠ 空でよい（一部のコンテンツにしか略称が無い）。空のときは正式名を使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('content_name');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('short_name');
        });
    }
};
