<?php

namespace App\Auth;

use App\Models\Person;
use App\Support\TestAccounts;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * people 名簿（Person）用のユーザープロバイダ。
 *
 * 標準の Eloquent プロバイダ（DBで照合）を拡張し、
 * 「テスト用アカウント（[[TestAccounts]]）」のときだけ DB を一切見ずに、
 * その場で組み立てた利用者を返す。それ以外は今までどおり DB で照合する。
 *
 * これにより、DBが無い／未接続のテスト環境でも決まった見本アカウントで
 * ログインでき、かつ実在アカウントのログインは通常どおり動く。
 */
class PersonUserProvider extends EloquentUserProvider
{
    /** ログイン後の毎リクエスト：セッションのIDから利用者を復元する。 */
    public function retrieveById($identifier)
    {
        if ($account = TestAccounts::findById($identifier)) {
            // savable＝保存もするテスト（実在の people 行を返す＝応募・希望が本当に保存される）。
            if (! empty($account['savable'])) {
                return Person::find($account['id']) ?? TestAccounts::toPerson($account);
            }

            return TestAccounts::toPerson($account);   // それ以外は DBに触れない見本
        }

        return parent::retrieveById($identifier);
    }

    /** ログイン時：メール等の資格情報で利用者を探す。 */
    public function retrieveByCredentials(array $credentials)
    {
        if ($account = TestAccounts::findByEmail($credentials['email'] ?? null)) {
            // savable＝保存もするテスト（実在の people 行を返す）。
            if (! empty($account['savable'])) {
                return Person::find($account['id']) ?? TestAccounts::toPerson($account);
            }

            return TestAccounts::toPerson($account);   // それ以外は DBに触れない見本
        }

        return parent::retrieveByCredentials($credentials);
    }

    /** パスワード照合。 */
    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        if ($account = TestAccounts::findById($user->getAuthIdentifier())) {
            return TestAccounts::checkPassword($account, $credentials['password'] ?? null);
        }

        return parent::validateCredentials($user, $credentials);
    }

    /** パスワード再ハッシュ：テストアカウントはDB保存しないので何もしない。 */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false)
    {
        if (TestAccounts::isTest($user)) {
            return;
        }

        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }

    /** 「ログイン状態を保持」のトークン更新：テストアカウントはDB保存しない。 */
    public function updateRememberToken(Authenticatable $user, $token)
    {
        if (TestAccounts::isTest($user)) {
            return;
        }

        parent::updateRememberToken($user, $token);
    }

    /** remember クッキーからの復元：テストアカウントは非対応（DB非依存を保つ）。 */
    public function retrieveByToken($identifier, $token)
    {
        if (TestAccounts::findById($identifier)) {
            return null;
        }

        return parent::retrieveByToken($identifier, $token);
    }
}
