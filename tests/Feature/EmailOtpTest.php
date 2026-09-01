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

    // ─────────────────────────────────────────────────────────────
    // ここから：入力の仕方でつまずかないこと（2026-09-01）
    //
    // ⚠ babaから「メールを受け取ってすぐ入れたのに『コードが違う』と弾かれる」と報告。
    //   再現したところ、**コードは合っているのに入力の形で落ちていた**：
    //   ・全角の数字（日本語入力が全角のまま打つ）
    //   ・1文字ずつ空白が入る（メールからの写し）
    //   ・入力欄の maxlength が 6 だったので、「 123456」と前の空白ごと貼ると
    //     6文字で切られて**静かに5桁**になる
    //   ログインできない＝何もできないので、ここは厚く見張る。
    // ─────────────────────────────────────────────────────────────

    /** ログインして /otp を開き、メールで届いたコードを返す。 */
    private function issuedCode(): string
    {
        Mail::fake();
        $this->makeUser();
        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'secret-pass']);
        $this->get('/otp');

        $code = null;
        Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return (string) $code;
    }

    /** 全角の数字（１２３４５６）でも通る。日本語入力が全角のままだとこうなる。 */
    public function test_fullwidth_digits_are_accepted(): void
    {
        $code = $this->issuedCode();

        $this->post('/otp', ['code' => mb_convert_kana($code, 'N')])
            ->assertRedirect('/dashboard');
        $this->assertTrue(session('twofa_ok'));
    }

    /** 空白・改行・ハイフンが混ざっていても通る（メールからの写し・コピペ）。 */
    #[\PHPUnit\Framework\Attributes\DataProvider('messyCodeShapes')]
    public function test_messy_but_correct_codes_are_accepted(string $shape): void
    {
        $code = $this->issuedCode();

        $input = match ($shape) {
            '前後に空白' => '  '.$code.' ',
            '1文字ずつ空白' => implode(' ', mb_str_split($code)),
            '改行つき' => $code."\n",
            '全角の空白' => '　'.$code.'　',
            '真ん中にハイフン' => substr($code, 0, 3).'-'.substr($code, 3),
            '「確認コード：」ごと貼った' => '確認コード： '.$code,
        };

        // ⚠ そのままの6桁を渡してしまっていないか（テストが何も見張らなくなるのを防ぐ）。
        $this->assertNotSame($code, $input, "入力『{$shape}』が作れていません。");

        $this->post('/otp', ['code' => $input])->assertRedirect('/dashboard');
    }

    public static function messyCodeShapes(): array
    {
        return [
            '前後に空白' => ['前後に空白'],
            '1文字ずつ空白' => ['1文字ずつ空白'],
            '改行つき' => ['改行つき'],
            '全角の空白' => ['全角の空白'],
            '真ん中にハイフン' => ['真ん中にハイフン'],
            '「確認コード：」ごと貼った' => ['「確認コード：」ごと貼った'],
        ];
    }

    /**
     * 6桁になっていないときは、「コードが違います」で終わらせず**何が起きたか**を伝える。
     * ⚠ ここが親切でないと、正しく写しているのに何度も弾かれて詰む。
     */
    public function test_a_code_that_is_not_six_digits_says_why(): void
    {
        $this->issuedCode();

        $this->from('/otp')->post('/otp', ['code' => '12345'])
            ->assertRedirect('/otp')
            ->assertSessionHasErrors('code');

        $this->assertStringContainsString('6桁', session('errors')->first('code'));
    }

    /**
     * ⚠ 入力欄の maxlength を 6 に戻さない。
     *   メールから「 123456」と前の空白ごとコピーすると6文字で切られ、
     *   **静かに5桁になって**正しく写しているのに弾かれる。
     */
    public function test_the_input_does_not_silently_cut_a_pasted_code(): void
    {
        $blade = (string) file_get_contents(resource_path('views/otp_challenge.blade.php'));

        $this->assertStringNotContainsString('maxlength="6"', $blade,
            '確認コードの入力欄が maxlength="6" に戻っています。'
            .'空白ごと貼り付けたときに1文字切られて、正しいコードが弾かれます。');
    }

    /**
     * 画面を開き直してもコードは1通だけ（何通も届くと、どれが正しいか分からなくなる）。
     * ⚠ ここが崩れると「新しいメールを開いたのに古いコードだった」という事故になる。
     */
    public function test_reloading_the_page_does_not_send_another_code(): void
    {
        Mail::fake();
        $this->makeUser();
        $this->post('/login', ['email' => 'otp@example.com', 'password' => 'secret-pass']);

        $this->get('/otp');
        $this->get('/otp');
        $this->get('/otp');

        $sent = 0;
        Mail::assertSent(LoginCodeMail::class, function () use (&$sent) {
            $sent++;

            return true;
        });
        $this->assertSame(1, $sent, '画面を開き直すたびにコードが送られています。');
    }
}
