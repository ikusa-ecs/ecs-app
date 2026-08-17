<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * 見本（ダミー）データのまとめ役。架空の社員・スタッフ・コンテンツ・案件を入れる。
 *
 * ★ここに入っているものは全部ニセモノ（メールは @example.com）。本番には入らない。
 *   許可の判定は各シーダーの DemoOnly（＝ECS_SEED_DEMO / APP_ENV=local）に任せる。
 *
 * なぜ本物（RoleRequirementSeeder＝必要アサイン人数リスト）と分けたか：
 * 以前は本物とニセモノが DatabaseSeeder に一緒に並んでいたため、
 * 「本番でも入れたい本物」だけを入れる手段が無かった。分けたことで
 *   ・本番　　　→ db:seed で本物だけ入る
 *   ・自分のPC → db:seed で今までどおり見本も入る
 * になる。
 *
 * 見本だけを入れ直したいとき：php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            ContentSeeder::class,                 // コンテンツ・マスタの見本（先に投入）
            ContentRoleRequirementSeeder::class,  // 規模別の必要人数（仮の見本／本物で後から上書きされる）
            PersonSeeder::class,                  // 人名簿（架空の社員・スタッフ）
            StaffProfileSeeder::class,            // できるポジション・NGペア（people を参照）
            ProjectSeeder::class,                 // 案件（ディレクター等で people を参照するため最後）

            // アサイン・応募・希望。これで migrate:fresh --seed 一発で
            // アサイン表・マイページ等が“埋まった状態”になる。
            DemoActivitySeeder::class,            // /staff-status・/assign-sheet 等が埋まる
            DemoMyPageSeeder::class,              // baba(E-007) のアサイン・営業担当案件（/mypage が埋まる）
        ]);
    }
}
