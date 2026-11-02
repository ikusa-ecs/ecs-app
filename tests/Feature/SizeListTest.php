<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * サイズ一覧（/sizes・2026-11-02 スタッフ要望）。
 *   ・社員以上は開ける。スタッフは「サイズ一覧を見られる」にチェックされた人だけ（ほかは403）。
 *   ・出すのは在籍中のスタッフと社員の 靴・服・身長 だけ。退職した人は出さない。
 *   ・チェックはスタッフ名簿の保存で付け外しできる。送られてこなければ変えない。
 */
class SizeListTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_can_open_and_what_is_shown(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => '社員一郎', 'shoe_size' => '27', 'shirt_size' => 'L']);
        $viewer = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '見る花子', 'can_view_sizes' => true, 'shoe_size' => '23.5', 'shirt_size' => 'S', 'height' => '158']);
        $plain = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '見ない次郎']);
        PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '辞めた三郎', 'active' => false, 'shoe_size' => '26']);

        $this->actingAsPerson($plain)->get('/sizes')->assertForbidden();

        $this->actingAsPerson($viewer)->get('/sizes')->assertOk()
            ->assertSee('見る花子')->assertSee('23.5')->assertSee('158')
            ->assertSee('社員一郎')->assertSee('27')
            ->assertDontSee('辞めた三郎')
            ->assertDontSee('/staff-invoice-check', false);   // スタッフに社員のメニューを出さない

        $this->actingAsPerson($emp)->get('/sizes')->assertOk()->assertSee('見る花子');
    }

    public function test_flag_is_saved_from_roster(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京']);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);

        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['size_viewer' => '1', 'ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue((bool) $s->fresh()->can_view_sizes);

        // 送られてこなければ変えない（古い画面からの保存で勝手に外さない）。
        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue((bool) $s->fresh()->can_view_sizes);

        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['size_viewer' => '0', 'ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse((bool) $s->fresh()->can_view_sizes);
    }
}
