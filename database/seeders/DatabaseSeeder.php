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
            RoleRequirementSeeder::class,         // 必要アサイン人数リスト（本物・CSV取込／見本の後で上書き＋商品追加）
            PersonSeeder::class,                  // 人名簿（社員・スタッフ）
            StaffProfileSeeder::class,            // スタッフのポジション可否・NGペア（people を参照）
            ProjectSeeder::class,                 // 案件（ディレクター等で people を参照するため最後）
        ]);

        // テスト/デモ環境のときだけ、アサイン・応募・希望などの動作確認用データも入れる
        // （ECS_TEST_LOGIN 有効時のみ）。これで migrate:fresh --seed 一発でアサイン表・
        // マイページ等が“埋まった状態”になる。本番（flag=false）では入らない。
        if (config('ecs.test_login', false)) {
            $this->call([
                DemoActivitySeeder::class,        // アサイン・応募・希望（/staff-status・/assign-sheet 等が埋まる）
                DemoMyPageSeeder::class,          // baba(E-007) のアサイン・営業担当案件（/mypage が埋まる）
            ]);
        }

        // DB接続版のテスト用ログイン（シーダー内で ECS_TEST_LOGIN を判定）。
        $this->call([
            TestLoginSeeder::class,
        ]);
    }
}
