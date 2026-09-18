<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 案件に「配信担当」を持たせる（2026-09-18・FBシート No.21 馬場さん）。
 *
 * 配信・中継のある案件は、当日それを回す人（または頼む外部業者）を決めておく必要がある。
 * これまでどこにも残す場所が無く、LINEやチャットの中に埋もれていた。
 *
 * 【2つに分けて持つ理由（2026-09-18 baba）】
 *   ・broadcast_owner_id   … 社員から選ぶ（**全拠点の社員**から選べる）。D・SD・物品担当と同じ持ち方。
 *   ・broadcast_owner_name … 外部業者の名前（名簿にいない相手）。自由入力。
 *   両方入れてもよい（例：社員が窓口・実作業は外部業者）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('broadcast_owner_id')->nullable()->after('goods_owner_id');
            $table->string('broadcast_owner_name')->nullable()->after('broadcast_owner_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['broadcast_owner_id', 'broadcast_owner_name']);
        });
    }
};
