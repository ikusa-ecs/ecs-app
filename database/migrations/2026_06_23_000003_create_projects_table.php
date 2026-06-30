<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects（案件）テーブル。設計書8章の projects に対応。
 * スプレッドシートの1ブロック＝1案件。全画面が読む中心データ。
 * 時刻系は '—' などの非時刻が混ざるためあえて文字列(HH:MM)で保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->string('id')->primary();                  // 例: P-2026-0001
            $table->string('project_name');                   // 案件名（コンテンツから決まる）
            $table->json('content_ids')->nullable();          // コンテンツ（複数可・contents参照）
            $table->string('yomi')->nullable();               // 確度ヨミ（確定/Aヨミ/Bヨミ/Cヨミ）
            $table->string('yomi_expected')->nullable();      // 確定見込み時期
            $table->string('scale')->nullable();              // 案件規模（大型/中型/小型）
            $table->string('category')->nullable();           // 区分（通常案件/追加案件）
            $table->boolean('is_recruiting')->default(true);  // スタッフ募集をするか
            $table->boolean('is_multi')->default(false);      // 複数案件か
            $table->string('site_category')->nullable();      // 現場種別（安定重視/育成/体力/通常・自動判定）
            $table->string('date_type')->default('本番');     // 日程種別（本番/予備日/リハ日）
            $table->string('parent_project_id')->nullable();  // 予備日・リハのとき紐づく本番案件ID

            $table->string('client')->nullable();             // クライアント
            $table->string('agency')->nullable();             // 代理店名
            $table->json('sales_owners')->nullable();         // セールス担当（社員・複数）
            $table->string('staff_role')->nullable();         // 担当体制

            $table->string('format')->nullable();             // 実施形態
            $table->string('online_tool')->nullable();        // オンラインツール
            $table->json('base_locations')->nullable();       // 対象拠点
            $table->string('broadcast')->nullable();          // 配信種別（なし/配信/中継）
            $table->string('operation_place')->nullable();    // 運営場所

            $table->date('start_date')->nullable();           // 開催日
            $table->string('start_time')->nullable();         // 集合時間（スタッフ・社内調整用）
            $table->string('staff_meeting_time')->nullable(); // スタッフ向け集合時間（公開用）
            $table->string('end_time')->nullable();           // 解散時間
            $table->string('event_enter_time')->nullable();   // イベント入場時刻
            $table->string('event_start_time')->nullable();   // イベント開始時刻
            $table->string('event_end_time')->nullable();     // イベント終了時刻
            $table->string('location')->nullable();           // 場所（住所）
            $table->boolean('is_outdoor')->nullable();        // 屋外現場か
            $table->string('lodging')->nullable();            // 宿泊（無/前泊有 等）
            $table->string('assembly_type')->nullable();      // 集合形式

            $table->integer('required_count')->nullable();    // 運営人数（＝必要人数）
            $table->boolean('count_tentative')->default(false);// 運営人数が仮か
            $table->integer('guest_count')->nullable();       // お客様人数
            $table->string('guest_count_type')->nullable();   // お客様人数が確定か募集か
            $table->integer('team_count')->nullable();        // チーム数
            $table->boolean('team_tentative')->default(false);// チーム数が仮か
            $table->boolean('is_repeat')->default(false);     // リピート案件か

            $table->string('catering')->nullable();           // ケータリング
            $table->boolean('alcohol')->nullable();           // お酒あり/なし
            $table->string('audio_equipment')->nullable();    // 音響機材
            $table->string('transport')->nullable();          // 移動・車両

            $table->string('pub_logo')->nullable();           // 実績公開：ロゴ
            $table->string('pub_camera')->nullable();         // 実績公開：カメラ
            $table->string('pub_article')->nullable();        // 実績公開：事例記事
            $table->string('pub_video')->nullable();          // 実績公開：動画

            $table->string('director_id')->nullable();        // ディレクター（people参照）
            $table->string('goods_owner_id')->nullable();     // 物品担当（people参照）
            $table->boolean('prep_line_sent')->default(false);// 準備チェック：LINE概要送付
            $table->boolean('prep_handover')->default(false); // 準備チェック：引き継ぎ
            $table->boolean('prep_script')->default(false);   // 準備チェック：台本
            $table->string('ops_sheet_url')->nullable();      // 運営シートURL

            $table->text('note')->nullable();                 // 備考
            $table->string('status')->nullable();             // ステータス（下書き/募集中/締切/確定/完了 等）
            $table->boolean('staff_published')->default(false);// スタッフ公開フラグ（背骨）

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
