<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 古い画面・別の人の画面が見えないように（2026-10-15 FBシートNo.24・25）。
 *
 *  No.24：スタッフ画面で 11/1 の案件が 11/3 と表示された（更新すると直る）
 *         ＝日付を「今日から何日後」で渡し、JSがスマホの今日に足していた＋古いページの写しが出ていた。
 *  No.25：マイページを更新すると一瞬「馬場さん」になる＝HTMLに見本の「馬」「baba@…」が書いてあった。
 */
class StalePageTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $id, string $permission)
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $id.'さん', 'role' => $permission === 'staff' ? 'staff' : 'employee',
            'permission' => $permission, 'office' => '東京', 'must_onboard' => false, 'active' => true,
            'email' => strtolower($id).'@example.com',
        ]);
    }

    public function test_ログイン中の画面はブラウザに写しを取らせない(): void
    {
        $staff = $this->person('S-001', 'staff');
        $res = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk();
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_スタッフ画面の日付はサーバーの今日を基準にする(): void
    {
        $staff = $this->person('S-001', 'staff');
        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('window.ECS_TODAY = "'.now()->toDateString().'"', false)
            ->assertSee('const today = ECS_baseToday();', false);
    }

    public function test_マイページに見本の名前やメールが書かれていない(): void
    {
        $me = $this->person('E-001', 'employee');
        $this->actingAsPerson($me)->get('/mypage')
            ->assertOk()
            ->assertDontSee('baba@ikusa.co.jp')
            ->assertDontSee('id="profAvatar">馬', false)
            ->assertSee('e-001@example.com');   // 本人のメールはデータとして渡っている
    }
}
