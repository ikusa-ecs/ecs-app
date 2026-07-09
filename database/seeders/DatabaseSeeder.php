<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ECS の見本データ（社員・スタッフ・コンテンツ・案件）。
        // ※すべて開発・動作確認用のダミー（メールは @example.com）。本番には入れない。
        $this->call([
            ContentSeeder::class,                 // コンテンツ・マスタ（先に投入）
            ContentRoleRequirementSeeder::class,  // コンテンツ別・規模別の必要人数（仮の見本／コンテンツの後）
            PersonSeeder::class,                  // 人名簿（社員・スタッフ）
            StaffProfileSeeder::class,            // スタッフのポジション可否・NGペア（people を参照）
            ProjectSeeder::class,                 // 案件（ディレクター等で people を参照するため最後）
            TestLoginSeeder::class,               // DB接続版のテスト用ログイン（ECS_TEST_LOGIN 有効時のみ）
        ]);
    }
}
