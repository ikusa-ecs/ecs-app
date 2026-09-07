<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「スタッフに社員用のメニュー・社員向けガイドを見せない」ことの見張り（2026-09-07）。
 *
 * 見つかった穴：マイプロフィール(/profile)・パスワード変更(/password)は
 *   スタッフも入れる画面なのに、社員側の土台(layouts.app)を使っているため、
 *   左メニュー（案件一覧・収支一覧・派遣一覧…）が丸ごと描画されていた。
 *   押しても EnsureTier が /staff-portal へ戻すので中身は守られていたが、
 *   「社内にどんな機能があるか」というメニュー名だけがスタッフに読めていた。
 *   同じ理由で /guide（社員側の全画面の使い方が書いてある）も開けてしまっていた。
 *
 * ⚠ ここが落ちたら「サイドバーの @if を外した」「/guide の tier:employee を外した」のどちらか。
 *   直す前に、この穴を作り直していないか確認する。
 * ⚠ 社員側でメニューが消えていないこと（＝直しすぎ）も一緒に見張る。
 */
class StaffSeesNoEmployeeMenuTest extends TestCase
{
    use RefreshDatabase;

    /** スタッフがマイプロフィールを開いても、社員用メニューが出ないこと。 */
    public function test_staff_profile_has_no_employee_menu(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $res = $this->actingAsPerson($staff)->get('/profile');

        $res->assertOk();
        // 社員用メニューの目印（リンクそのもの）が1つも出ていないこと
        $res->assertDontSee('href="/dashboard"', false);
        $res->assertDontSee('href="/projects"', false);
        $res->assertDontSee('href="/finance-list"', false);
        $res->assertDontSee('href="/dispatch-list"', false);
        $res->assertDontSee('href="/staff"', false);
        // 代わりに、自分の画面へ戻る道が出ていること（行き止まりにしない）
        $res->assertSee('スタッフ画面へ戻る');
    }

    /** パスワード変更も同じ土台なので、こちらも塞がっていること。 */
    public function test_staff_password_page_has_no_employee_menu(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $res = $this->actingAsPerson($staff)->get('/password');

        $res->assertOk();
        $res->assertDontSee('href="/dashboard"', false);
        $res->assertDontSee('href="/finance-list"', false);
    }

    /** 社員側では今までどおりメニューが出ること（＝直しすぎていないこと）。 */
    public function test_employee_still_sees_the_menu(): void
    {
        $employee = PersonFactory::new()->create();   // 既定＝社員

        $res = $this->actingAsPerson($employee)->get('/profile');

        $res->assertOk();
        $res->assertSee('href="/dashboard"', false);
        $res->assertSee('href="/finance-list"', false);
    }

    /** 社員向けガイドはスタッフには開けず、自分のスタッフ画面へ戻されること。 */
    public function test_staff_cannot_open_employee_guide(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/guide')->assertRedirect('/staff-portal');
    }

    /** スタッフ向けガイドは今までどおり開けること（塞ぎすぎていないこと）。 */
    public function test_staff_can_still_open_staff_guide(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/guide-staff')->assertOk();
    }

    /** 社員も社員向けガイドを開けること。 */
    public function test_employee_can_open_employee_guide(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->get('/guide')->assertOk();
    }
}
