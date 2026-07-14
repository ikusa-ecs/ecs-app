<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2段階認証（Fortify）用のカラムを people 名簿に追加する。
 * ・two_factor_secret          … 認証アプリと共有する秘密（暗号化して保存）
 * ・two_factor_recovery_codes  … 端末を無くしたとき用のリカバリコード（暗号化して保存）
 * ・two_factor_confirmed_at    … 本人がコード確認して2段階認証を「有効化」した日時
 * ログインは people 名簿で行うため、標準の users 表ではなく people に持たせる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->text('two_factor_secret')
                ->after('password')
                ->nullable();

            $table->text('two_factor_recovery_codes')
                ->after('two_factor_secret')
                ->nullable();

            $table->timestamp('two_factor_confirmed_at')
                ->after('two_factor_recovery_codes')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
