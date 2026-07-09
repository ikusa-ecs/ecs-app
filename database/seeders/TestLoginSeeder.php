<?php

namespace Database\Seeders;

use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * DB接続版のテスト用ログインアカウント。
 *
 * people テーブルに“実在する”テストアカウントを入れる（DB不要版＝App\Support\TestAccounts とは別物）。
 * DBがつながっている環境で、実際の名簿・アサイン等の関連データも含めてテストしたいとき用。
 *
 * ・何度実行しても重複しない（id で updateOrCreate）。
 * ・ECS_TEST_LOGIN が有効なときだけ投入する（本番で無効化すれば入らない）。
 * ・パスワードは people の cast（'password' => 'hashed'）で自動的に暗号化される。
 *
 * 単体実行：php artisan db:seed --class=TestLoginSeeder
 */
class TestLoginSeeder extends Seeder
{
    public function run(): void
    {
        // 本番などでテストログインを無効にしている場合は投入しない。
        if (! config('ecs.test_login', false)) {
            return;
        }

        // テスト社員（管理者）＝全操作を確認できる。
        Person::updateOrCreate(['id' => 'E-900'], [
            'role'       => 'employee',
            'name'       => 'テスト社員（DB・管理者）',
            'email'      => 'test-db@example.com',
            'password'   => 'test',              // cast で自動ハッシュ
            'permission' => 'admin',
            'department' => 'イベプラ',
            'office'     => '東京',
            'hire_date'  => '2024-04-01',
            'active'     => true,
            'is_admin'   => true,
        ]);

        // テスト社員（一般）＝業務画面は見えるが削除・マスタ等は不可。
        Person::updateOrCreate(['id' => 'E-903'], [
            'role'       => 'employee',
            'name'       => 'テスト社員（DB・一般）',
            'email'      => 'test-db-emp@example.com',
            'password'   => 'test',
            'permission' => 'employee',
            'department' => 'セールス',
            'office'     => '東京',
            'hire_date'  => '2022-04-01',
            'active'     => true,
        ]);

        // テスト管理者（アサイン担当）＝社員に加えアカウント発行・名簿取込などが可。
        Person::updateOrCreate(['id' => 'E-902'], [
            'role'       => 'employee',
            'name'       => 'テスト管理者（DB・アサイン担当）',
            'email'      => 'test-db-mgr@example.com',
            'password'   => 'test',
            'permission' => 'manager',
            'department' => 'イベプラ',
            'office'     => '東京',
            'hire_date'  => '2021-04-01',
            'active'     => true,
        ]);

        // テストスタッフ＝スタッフ画面（/staff-portal）の確認用。
        Person::updateOrCreate(['id' => 'S-900'], [
            'role'             => 'staff',
            'name'             => 'テストスタッフ（DB）',
            'email'            => 'test-db-staff@example.com',
            'password'         => 'test',        // cast で自動ハッシュ
            'permission'       => 'staff',
            'office'           => '東京',
            'employment_type'  => 'アルバイト',
            'experience_count' => 0,
            'hire_date'        => '2025-04-01',
            'active'           => true,
        ]);
    }
}
