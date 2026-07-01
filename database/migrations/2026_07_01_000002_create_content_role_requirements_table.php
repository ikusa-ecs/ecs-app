<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コンテンツ別・規模別の「必要ポジションと人数」。
 *
 * コンテンツ（運動会 など）は、参加者の規模（小型／中型／大型）によって
 * 必要な役割（D・MC・FC…）とアサイン人数が変わる。その基準値をここに持つ。
 * 将来、案件の必要人数の自動見積り（アサイン画面の「必要◯名」）に使える。
 *
 * 1行＝コンテンツ×規模×ポジション の必要人数。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_role_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('content_id');            // contents.id（CT-001 等）
            $table->string('scale');                 // 小型／中型／大型
            $table->string('position');              // 役割コード（AssignmentRole）
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['content_id', 'scale', 'position']);
            $table->index('content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_role_requirements');
    }
};
