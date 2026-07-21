<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * パスワード再設定（お忘れの方）用の一時トークン置き場。
 *
 * ・メールで送るリンクに入れる「合言葉（トークン）」を、ここに“ハッシュ化して”保存する。
 *   （平文のまま持たない＝万一DBが漏れても悪用しづらくするため。2段階認証コードと同じ考え方。）
 * ・1メールアドレスにつき1件（email が主キー）。もう一度申請したら古いトークンは上書きされる。
 * ・created_at からの経過時間で有効期限（60分）を判定し、使い終わったら行ごと削除する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->primary();   // 宛先メール（＝申請者）
            $table->string('token');               // トークンのハッシュ（平文はメールのURLだけ）
            $table->timestamp('created_at')->nullable(); // 発行時刻（有効期限の起点）
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
