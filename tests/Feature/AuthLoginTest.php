<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ログイン（Fortify 経由）の基本動作を保証するテスト。
 * DB不要のテスト用アカウント（App\Support\TestAccounts）を使うので、seed なしで回せる。
 */
class AuthLoginTest extends TestCase
{
    public function test_administrator_can_login_and_lands_on_dashboard(): void
    {
        $res = $this->post('/login', ['email' => 'test@ecs.local', 'password' => 'test']);

        $res->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_staff_lands_on_staff_portal(): void
    {
        $res = $this->post('/login', ['email' => 'test-staff@ecs.local', 'password' => 'test']);

        $res->assertRedirect('/staff-portal');
        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $res = $this->from('/')->post('/login', ['email' => 'test@ecs.local', 'password' => 'wrong']);

        $res->assertRedirect('/');
        $res->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $res = $this->get('/dashboard');

        $res->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $this->post('/login', ['email' => 'test-emp@ecs.local', 'password' => 'test']);
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->assertGuest();
    }

    public function test_login_page_renders_for_guest(): void
    {
        $this->get('/')->assertOk()->assertSee('ログイン');
    }
}
