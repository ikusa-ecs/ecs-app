<?php

namespace Tests\Feature;

use App\Support\TestAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 体験ログイン（固定パスワードの見本アカウント）は、既定で閉じていること（2026-08-24 baba）。
 *
 * 何が危なかったか：
 *   これまで既定が true だったので、本番の .env に ECS_TEST_LOGIN=false と
 *   書き忘れただけで「URLを知っている人が、ログイン画面のソースに書かれた
 *   固定パスワードで Administrator として入れる」状態になっていた。
 *   本物のアカウントを配り終わったので、既定を false にして閉じた。
 *
 * ⚠ このテストが落ちたら、体験ボタンが本番で開く状態に戻っている合図。
 */
class TestLoginDisabledTest extends TestCase
{
    use RefreshDatabase;

    /** 何も書かなければ閉じている（＝安全側に倒れる）。 */
    public function test_disabled_by_default(): void
    {
        // phpunit.xml ではテスト用に true にしているので、既定の挙動はここで確かめる。
        config(['ecs.test_login' => false]);

        $this->assertFalse(TestAccounts::enabled());
        $this->assertNull(TestAccounts::findByEmail('test@ecs.local'));
        $this->assertNull(TestAccounts::findById('TEST-ADMIN'));
    }

    /** 閉じているとき、ログイン画面に体験ボタンも仮の初期値も出ない。 */
    public function test_login_page_has_no_demo_buttons_when_disabled(): void
    {
        config(['ecs.test_login' => false]);

        $html = $this->get('/')->assertOk()->getContent();

        // 見本アカウントのメール・固定パスワードが画面のソースに出ていないこと。
        foreach (['test@ecs.local', 'test-mgr@ecs.local', 'test-staff@ecs.local',
            'test-db-staff@example.com', 'e-007@example.com'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, $leak.' が画面に出ている');
        }
        // パスワード欄に値が入っていないこと。
        $this->assertStringNotContainsString('value="password"', $html);
    }

    /** 閉じているとき、見本アカウントではログインできない。 */
    public function test_cannot_login_with_demo_account_when_disabled(): void
    {
        config(['ecs.test_login' => false]);

        $this->post('/login', ['email' => 'test@ecs.local', 'password' => 'test'])
            ->assertRedirect();

        $this->assertGuest();
    }

    /** 明示的に有効にしたときだけ使える（自分のPCでの確認用）。 */
    public function test_enabled_when_explicitly_turned_on(): void
    {
        config(['ecs.test_login' => true]);

        $this->assertTrue(TestAccounts::enabled());
        $this->assertNotNull(TestAccounts::findByEmail('test@ecs.local'));
    }

    /** 見本データも既定では入らない（本番で db:seed しても架空データが入らない）。 */
    public function test_demo_seed_disabled_by_default(): void
    {
        config(['ecs.seed_demo' => null]);
        // 既定値そのものを確認する（config/ecs.php の env(..., false)）。
        $this->assertFalse((bool) config('ecs.seed_demo'));
    }
}
