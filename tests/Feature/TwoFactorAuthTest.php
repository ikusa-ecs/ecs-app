<?php

namespace Tests\Feature;

use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * 2段階認証（Fortify）の実動線を、DBの実ユーザーで通しで検証する。
 * 有効化 → 認証アプリのコードで確認 → 再ログイン時にコードを要求 → コードで突破。
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): Person
    {
        return Person::create([
            'id' => 'E-999',
            'role' => 'employee',
            'permission' => 'admin',
            'name' => '2FAテスト社員',
            'email' => 'twofa@example.com',
            'password' => 'secret-pass',   // casts で自動ハッシュ化
            'active' => true,
            'must_onboard' => false,
        ]);
    }

    /** 保存されている暗号化済みの秘密から、いまのTOTPコード（6桁）を作る。 */
    private function currentCode(Person $user): string
    {
        $secret = decrypt($user->fresh()->two_factor_secret);

        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_full_two_factor_flow(): void
    {
        $user = $this->makeUser();

        // 1) 通常ログイン（まだ2FAオフ）→ 業務トップへ。
        $this->post('/login', ['email' => 'twofa@example.com', 'password' => 'secret-pass'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        // 2) 2段階認証を有効化（秘密鍵とリカバリコードが作られる／まだ未確認）。
        $this->post('/user/two-factor-authentication')->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at, '確認前は confirmed_at は空のはず');

        // 3) 認証アプリの6桁コードで確認 → 有効化完了。
        $this->post('/user/confirmed-two-factor-authentication', ['code' => $this->currentCode($user)])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at, '確認後は confirmed_at が入るはず');

        // 4) 一度ログアウト。
        $this->post('/logout');
        $this->assertGuest();

        // 5) 再ログイン → 2FAがONなので、すぐには入れず「コード入力画面」へ回される。
        $this->post('/login', ['email' => 'twofa@example.com', 'password' => 'secret-pass'])
            ->assertRedirect('/two-factor-challenge');
        $this->assertGuest();   // まだコード未入力なので認証は完了していない

        // 6) 突破する。ここは時刻に依存しない「リカバリコード」で確実に検証する
        //    （認証アプリの6桁コードは壁時計時刻に依存しテストが不安定になるため）。
        $recovery = $user->fresh()->recoveryCodes()[0];
        $resp = $this->post('/two-factor-challenge', ['recovery_code' => $recovery]);
        $resp->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_wrong_code_does_not_authenticate(): void
    {
        $user = $this->makeUser();

        $this->post('/login', ['email' => 'twofa@example.com', 'password' => 'secret-pass']);
        $this->post('/user/two-factor-authentication');
        $this->post('/user/confirmed-two-factor-authentication', ['code' => $this->currentCode($user)]);
        $this->post('/logout');

        // 再ログイン → コード画面へ。
        $this->post('/login', ['email' => 'twofa@example.com', 'password' => 'secret-pass'])
            ->assertRedirect('/two-factor-challenge');

        // 間違ったコードでは通らない。
        $this->post('/two-factor-challenge', ['code' => '000000']);
        $this->assertGuest();
    }
}
