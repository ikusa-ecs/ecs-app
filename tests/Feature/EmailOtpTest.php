<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 2段階認証（メールでコード）の実動線を検証する。
 * ログイン → 業務画面は一旦ブロックされ /otp へ → メールのコードで通過。
 */
class EmailOtpTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): Person
    {
        return Person::create([
            'id' => 'E-999',
            'role' => 'employee',
            'permission' => 'admin',
            'name' => 'OTPテスト社員',
            'email' => 'otp@example.com',
            'password' => 'secret-pass',
            'active' => true,
            'must_onboard' => false,
        ]);
    }

    public function test_login_requires_email_code_before_business_screens(): void
    {
        Mail::fake();
        $this->makeUser();

        // 1) ログイン成功。
        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'secret-pass']);
        $this->assertAuthenticated();

        // 2) まだコード未入力なので、業務画面はコード入力ページへ回される。
        $this->get('/dashboard')->assertRedirect('/otp');

        // 3) コード入力ページを開くと、コードがメールで送られる。
        $this->get('/otp')->assertOk();
        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('otp@example.com');
        });
        $this->assertNotNull($code);

        // 4) 正しいコードで通過 → 業務トップへ。
        $this->post('/otp', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertTrue(session('twofa_ok'));
    }

    public function test_wrong_code_stays_blocked(): void
    {
        Mail::fake();
        $this->makeUser();

        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'secret-pass']);
        $this->get('/otp');   // コード発行

        $this->from('/otp')->post('/otp', ['code' => '000000'])
            ->assertRedirect('/otp')
            ->assertSessionHasErrors('code');

        // まだ通過していないので、業務画面はブロックされたまま。
        $this->get('/dashboard')->assertRedirect('/otp');
    }

    public function test_test_login_account_skips_email_code(): void
    {
        // テスト用ログイン（DBに無い開発用）は2段階認証の対象外＝すぐ業務画面へ。
        $this->post('/login', ['email' => 'test@ecs.local', 'password' => 'test']);
        $this->assertAuthenticated();

        $this->get('/dashboard')->assertOk();
    }
}
