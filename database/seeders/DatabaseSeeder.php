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
        // ① 見本（ダミー）データ。架空の社員・スタッフ・コンテンツ・案件。
        //    ★本番には入らない（各シーダーが DemoOnly で自分で判定して止まる）。
        //    ②の本物で仮値を上書きしてもらうため、②より先に呼ぶ。
        $this->call(DemoSeeder::class);

        // ② 本物の初期データ。本番でも入れる。
        //    必要アサイン人数リスト（IKUSA公式・CSV取込）＝①の仮の人数を上書きし、
        //    リストにあるコンテンツも追加する。ここは環境で分けない。
        $this->call(RoleRequirementSeeder::class);

        // ③ DB接続版のテスト用ログイン。ECS_TEST_LOGIN が有効なときだけ入る
        //    （判定はシーダー内。本番は既定で無効なので入らない）。
        $this->call(TestLoginSeeder::class);
    }
}
