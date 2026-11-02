<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * サイズ一覧（/sizes・2026-11-02 スタッフ要望）。
 *   ・社員以上は開ける。スタッフは「常勤」（スタッフ名簿）で、かつサイズ一覧の画面で「見せる」にされた人だけ（ほかは403）。
 *     2026-11-02 小沼さん「ずっと見るわけじゃないので、つど権限を付与する」＝常勤の印と見せる／見せないを分けた。
 *   ・出すのは在籍中のスタッフと社員の 靴・服・身長 だけ。退職した人は出さない。スタッフはサイズを登録している人だけ。
 *   ・常勤の印はスタッフ名簿の保存で付け外し。送られてこなければ変えない。
 */
class SizeListTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_can_open_and_what_is_shown(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => '社員一郎', 'shoe_size' => '27', 'shirt_size' => 'L']);
        $viewer = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '見る花子', 'is_fulltime' => true, 'can_view_sizes' => true, 'shoe_size' => '23.5', 'shirt_size' => 'S', 'height' => '158']);
        $notShown = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '常勤だけ次郎', 'is_fulltime' => true, 'can_view_sizes' => false]);
        $plain = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '見ない四郎']);
        $notFull = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '常勤でない五郎', 'is_fulltime' => false, 'can_view_sizes' => true]);
        PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '辞めた三郎', 'active' => false, 'shoe_size' => '26']);

        $this->actingAsPerson($plain)->get('/sizes')->assertForbidden();
        $this->actingAsPerson($notShown)->get('/sizes')->assertForbidden();   // 常勤でも、見せていなければ開けない
        $this->actingAsPerson($notFull)->get('/sizes')->assertForbidden();    // 常勤でなければ、見せる印が残っていても開けない

        $this->actingAsPerson($viewer)->get('/sizes')->assertOk()
            ->assertSee('見る花子')->assertSee('23.5')->assertSee('158')
            ->assertSee('社員一郎')->assertSee('27')
            ->assertDontSee('辞めた三郎')
            ->assertDontSee('見ない四郎')                       // サイズを登録していないスタッフは出さない
            ->assertDontSee('この一覧を見せる常勤スタッフ')       // 付け外しは社員だけ
            ->assertDontSee('/staff-invoice-check', false);   // スタッフに社員のメニューを出さない

        $this->actingAsPerson($emp)->get('/sizes')->assertOk()
            ->assertSee('見る花子')
            ->assertSee('この一覧を見せる常勤スタッフ')
            ->assertSee('data-id="'.$notShown->id.'"', false)
            ->assertDontSee('data-id="'.$plain->id.'"', false);   // 常勤でない人は付け外しの欄に出ない
    }

    public function test_employee_grants_and_revokes_viewing(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京']);
        $full = PersonFactory::new()->staff()->create(['office' => '東京', 'is_fulltime' => true]);
        $part = PersonFactory::new()->staff()->create(['office' => '東京', 'is_fulltime' => false]);

        $this->actingAsPerson($emp)->postJson('/sizes/grant', ['id' => $full->id, 'on' => true])->assertOk();
        $this->assertTrue((bool) $full->fresh()->can_view_sizes);
        $this->actingAsPerson($full->fresh())->get('/sizes')->assertOk();

        $this->actingAsPerson($emp)->postJson('/sizes/grant', ['id' => $full->id, 'on' => false])->assertOk();
        $this->assertFalse((bool) $full->fresh()->can_view_sizes);

        // 常勤でない人には見せられない。スタッフは付け外しできない。
        $this->actingAsPerson($emp)->postJson('/sizes/grant', ['id' => $part->id, 'on' => true])->assertStatus(422);
        $this->actingAsPerson($full->fresh())->postJson('/sizes/grant', ['id' => $full->id, 'on' => true])->assertForbidden();
    }

    public function test_fulltime_is_saved_from_roster(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京']);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);

        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['fulltime' => '1', 'ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue((bool) $s->fresh()->is_fulltime);
        $this->assertFalse((bool) $s->fresh()->can_view_sizes, '常勤にしただけでは見せない');

        // 送られてこなければ変えない（古い画面からの保存で勝手に外さない）。
        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertTrue((bool) $s->fresh()->is_fulltime);

        $this->actingAsPerson($emp)->post('/staff/'.$s->id.'/edit', ['fulltime' => '0', 'ng' => '', 'impression' => ''], ['Accept' => 'application/json'])->assertOk();
        $this->assertFalse((bool) $s->fresh()->is_fulltime);
    }

    /** スタッフと社員は分けて、社員は部署ごと（イベプラが先頭）。部署が空の社員も「その他」に出す（2026-11-02 小沼さん）。 */
    public function test_staff_and_employees_are_split_and_employees_grouped_by_department(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => 'セールス太郎', 'department' => 'セールス']);
        PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => 'イベプラ花子', 'department' => 'イベプラ']);
        PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => '部署なし次郎', 'department' => null]);
        PersonFactory::new()->staff()->create(['office' => '東京', 'name' => 'スタッフ一子', 'shoe_size' => '24']);

        $data = $this->actingAsPerson($me)->get('/sizes')->assertOk()
            ->assertSeeInOrder(['スタッフ（', 'スタッフ一子', '社員（', 'イベプラ（', 'イベプラ花子', 'セールス（', 'セールス太郎', 'その他（', '部署なし次郎'])
            ->original->getData();
        $this->assertSame(['イベプラ', 'セールス', 'その他'], array_keys($data['employees']));
        $this->assertSame(['スタッフ一子'], collect($data['staff'])->pluck('name')->all());
    }
}
