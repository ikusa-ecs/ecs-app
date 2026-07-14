<?php

namespace App\Support;

use App\Models\Person;

/**
 * テスト用ログイン（DB不要）。
 *
 * ねらい：DB（people テーブル）が無い・未接続のテスト環境でも、決まった見本アカウントで
 * ログインして画面を確認できるようにする。ここに埋め込んだメール／パスワードのときだけ
 * DBを一切見ずに「その場で組み立てた利用者」でログインさせる。
 * 実在アカウント（people 名簿）でのログインは今までどおり DB で照合される。
 *
 * ⚠ 本番公開前に .env で ECS_TEST_LOGIN=false にして無効化すること
 *   （このアカウントは固定パスワードのため）。
 */
class TestAccounts
{
    /** テスト用アカウント一覧（DBには存在しない・コード埋め込み）。 */
    private static function accounts(): array
    {
        return [
            // ── 4段階の権限それぞれのテスト用（画面の違いを確認できるように）──
            [
                'id'         => 'TEST-STAFF',
                'name'       => 'テストスタッフ',
                'email'      => 'test-staff@ecs.local',
                'password'   => 'test',
                'role'       => 'staff',       // スタッフ → ログイン後は /staff-portal（自分の画面のみ）
                'permission' => 'staff',
            ],
            [
                'id'         => 'TEST-EMP',
                'name'       => 'テスト社員（一般）',
                'email'      => 'test-emp@ecs.local',
                'password'   => 'test',
                'role'       => 'employee',    // 社員 → 業務画面が見える（削除・マスタ等は不可）
                'permission' => 'employee',
            ],
            [
                'id'         => 'TEST-MGR',
                'name'       => 'テスト管理者（アサイン担当）',
                'email'      => 'test-mgr@ecs.local',
                'password'   => 'test',
                'role'       => 'employee',    // 管理者 → 社員に加えアカウント発行・名簿取込などが可
                'permission' => 'manager',
            ],
            [
                'id'         => 'TEST-ADMIN',
                'name'       => 'テストAdministrator',
                'email'      => 'test@ecs.local',
                'password'   => 'test',
                'role'       => 'employee',    // Administrator → 全操作OK（削除/権限付与/マスタも）
                'permission' => 'admin',
            ],
            // ── 初回ログイン体験用（管理者に発行された直後の新人スタッフ）──
            //   ログインすると初期設定ページ（パスワード変更＋プロフィール入力）へ強制的に誘導される。
            [
                'id'           => 'TEST-ONBOARD',
                'name'         => '新人スタッフ（初回ログイン）',
                'email'        => 'test-onboard@ecs.local',
                'password'     => 'test',
                'role'         => 'staff',
                'permission'   => 'staff',
                'must_onboard' => true,        // ← 初期設定がまだ＝初回セットアップへ誘導
            ],
        ];
    }

    /** テストログインが有効か（.env の ECS_TEST_LOGIN）。 */
    public static function enabled(): bool
    {
        return (bool) config('ecs.test_login', false);
    }

    /** メールからテストアカウント定義を探す（無ければ null）。 */
    public static function findByEmail(?string $email): ?array
    {
        if (! self::enabled() || $email === null) {
            return null;
        }

        $email = strtolower(trim($email));
        foreach (self::accounts() as $a) {
            if (strtolower($a['email']) === $email) {
                return $a;
            }
        }

        return null;
    }

    /** ID からテストアカウント定義を探す（無ければ null）。 */
    public static function findById($id): ?array
    {
        if (! self::enabled() || $id === null) {
            return null;
        }

        foreach (self::accounts() as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }

        return null;
    }

    /** 定義から“保存しない”Person を組み立てる（DBには触れない）。 */
    public static function toPerson(array $a): Person
    {
        $person = new Person();
        // fill ではなく setRawAttributes で直接入れる（キャストは読み取り時に効く）。
        $person->setRawAttributes([
            'id'           => $a['id'],
            'name'         => $a['name'],
            'email'        => $a['email'],
            'role'         => $a['role'],
            'permission'   => $a['permission'],
            'active'       => true,
            'must_onboard' => $a['must_onboard'] ?? false,   // 初回設定が必要か（既定＝不要）
        ], true);
        // 「新規保存が要る行」と誤解されないよう既存扱いにする（が、save は呼ばない）。
        $person->exists = true;

        return $person;
    }

    /** その利用者がテストアカウントか。 */
    public static function isTest(?object $user): bool
    {
        return $user instanceof Person && self::findById($user->getAuthIdentifier()) !== null;
    }

    /** テストアカウントのパスワード照合（平文・固定）。 */
    public static function checkPassword(array $account, ?string $password): bool
    {
        return is_string($password) && hash_equals($account['password'], $password);
    }
}
