<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * people（利用者＝社員・スタッフ共通）テーブル。
 * 設計書8章のとおり、社員もスタッフも1テーブル＋role で区別する（19.1 R-1 の確定方針）。
 * 区分（新人/中堅/ベテラン）は保存せず、hire_date から都度計算する（モデルの skill_level で算出）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            // ── 共通項目 ──
            $table->string('id')->primary();                 // 例: E-001（社員）/ S-001（スタッフ）
            $table->string('role');                          // employee=社員 / staff=スタッフ
            $table->string('name');                          // 氏名
            $table->string('email')->nullable()->unique();   // ログイン用メール（重複不可・自己登録の安全装置）
            $table->date('hire_date')->nullable();           // 入社日/登録日（区分の年数計算の元）
            $table->boolean('active')->default(true);        // 在籍・稼働中フラグ（退職は false で無効化）

            // ── 社員（employee）固有 ──
            $table->string('department')->nullable();        // 区分（イベプラ/セールス/クリエイティブ）
            $table->json('experienced_contents')->nullable();// 経験のあるコンテンツ
            $table->json('director_contents')->nullable();   // Dの経験があるコンテンツ
            $table->string('shirt_size')->nullable();
            $table->string('shoe_size')->nullable();
            $table->boolean('is_admin')->default(false);     // 全権管理者か

            // ── スタッフ（staff）固有 ──
            $table->string('employment_type')->nullable();   // 雇用区分（社員/現場運営スタッフ 等）
            $table->boolean('is_exclusive')->nullable();     // 自社専属か
            $table->integer('monthly_cap')->nullable();      // 月間アサイン上限（専属は20）
            $table->integer('experience_count')->nullable(); // 通算参加回数（参考値・本番複数日は日数分）
            $table->integer('desired_monthly')->nullable();  // 月希望件数
            $table->text('appeal')->nullable();              // 一言アピール（本人入力）
            $table->text('liked_contents')->nullable();      // 好きなコンテンツ（本人入力）
            $table->text('disliked_contents')->nullable();   // 苦手なコンテンツ（本人入力）
            $table->text('strong_positions')->nullable();    // 得意なポジション（自由記述・本人入力）
            $table->text('weak_positions')->nullable();      // 苦手なポジション（自由記述・本人入力）
            $table->boolean('mc_audition_passed')->nullable();// MCオーディション合格
            $table->boolean('can_kigurumi')->nullable();     // 着ぐるみOK
            $table->boolean('can_stay_over')->nullable();    // 前泊・後泊OK
            $table->string('driving_level')->nullable();     // 運転可否（なし/普通サイズ可/ハイエースも可）
            $table->string('english_level')->nullable();     // 英語力
            $table->boolean('can_follow_newbie')->nullable();// 新人フォロー可
            $table->boolean('self_starter')->nullable();     // 自分で考えて動ける
            $table->boolean('improves_atmosphere')->nullable();// 現場の空気を良くする
            $table->string('client_rating')->nullable();     // クライアント評価
            $table->boolean('oversleeper')->nullable();      // 寝坊常習（朝案件NG）
            $table->text('risk_notes')->nullable();          // 遅刻・欠勤等のリスクメモ
            $table->text('planner_impression')->nullable();  // イベプラからの雰囲気/初回所感（社員が入力）
            $table->boolean('sensitive_care')->nullable();   // 無理させない配慮が必要か

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
