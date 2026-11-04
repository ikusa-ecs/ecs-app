<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
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

        $data = $this->actingAsPerson($viewer)->get('/sizes')->assertOk()
            ->assertSee('見る花子')->assertSee('23.5')->assertSee('158')
            ->assertSee('社員一郎')->assertSee('27')
            ->assertDontSee('辞めた三郎')
            ->assertDontSee('この一覧を見せる常勤スタッフ')       // 付け外しは社員だけ
            ->assertDontSee('/staff-invoice-check', false)    // スタッフに社員のメニューを出さない
            ->original->getData();
        // サイズを登録していないスタッフは表に出さない。名前だけ「未登録」として持つ（探したときに分かるように）。
        $this->assertNotContains('見ない四郎', collect($data['staff'])->pluck('name')->all());
        $this->assertContains('見ない四郎', collect($data['unregistered'])->pluck('name')->all());
        $this->assertNotContains('見る花子', collect($data['unregistered'])->pluck('name')->all());
        $this->assertNotContains('辞めた三郎', collect($data['unregistered'])->pluck('name')->all());

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

    /** 日付から選ぶ（2026-11-04）：その日の案件と、入っている人（ポジション・サイズ）を返す。キャンセル・外した人・別の日・他拠点は出さない。 */
    public function test_day_lists_projects_and_members_with_position(): void
    {
        $emp = PersonFactory::new()->create(['role' => 'employee', 'permission' => 'employee', 'office' => '東京', 'name' => '社員D男', 'shoe_size' => '27', 'shirt_size' => 'L']);
        $a = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => 'FC花子', 'shoe_size' => '23', 'shirt_size' => 'S']);
        $b = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '未登録太郎']);
        $gone = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '外れた次郎']);
        $plain = PersonFactory::new()->staff()->create(['office' => '東京']);

        $p = ProjectFactory::new()->create(['start_date' => '2026-11-07', 'office' => '東京', 'project_name' => 'ファミリーフェス', 'client' => '福井銀行', 'start_time' => '7:00']);
        $cancelled = ProjectFactory::new()->create(['start_date' => '2026-11-07', 'office' => '東京', 'project_name' => '中止案件', 'is_cancelled' => true]);
        $other = ProjectFactory::new()->create(['start_date' => '2026-11-08', 'office' => '東京', 'project_name' => '別の日案件']);
        $osaka = ProjectFactory::new()->create(['start_date' => '2026-11-07', 'office' => '大阪', 'project_name' => '大阪案件']);
        foreach ([[$a, 'FC', '仮'], [$emp, 'D', '確定'], [$b, 'CK', '確定'], [$gone, 'FC', 'キャンセル']] as [$who, $role, $st]) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'role' => $role, 'date' => '2026-11-07', 'status' => $st]);
        }
        Assignment::create(['project_id' => $cancelled->id, 'staff_id' => $a->id, 'role' => 'FC', 'date' => '2026-11-07', 'status' => '確定']);

        $this->actingAsPerson($plain)->getJson('/sizes/day?date=2026-11-07')->assertForbidden();
        $this->actingAsPerson($emp)->getJson('/sizes/day?date=bad')->assertStatus(422);

        $j = $this->actingAsPerson($emp)->getJson('/sizes/day?date=2026-11-07')->assertOk()->json();
        $this->assertSame([$p->id], collect($j['projects'])->pluck('id')->all());   // 中止・別の日・他拠点は出ない
        $pj = $j['projects'][0];
        $this->assertSame('福井銀行', $pj['client']);
        $this->assertSame('7:00', $pj['meet']);
        // 並びはポジション順（D→FC→CK）。外した人（キャンセル）は出ない。未登録の人も出す。
        $this->assertSame(['社員D男', 'FC花子', '未登録太郎'], collect($pj['members'])->pluck('name')->all());
        $this->assertSame(['D', 'FC', 'CK'], collect($pj['members'])->pluck('role')->all());
        $this->assertTrue($pj['members'][1]['kari']);
        $this->assertSame('23', $pj['members'][1]['shoe']);
        $this->assertSame('', $pj['members'][2]['wear']);

        $this->actingAsPerson($emp)->get('/sizes')->assertOk()->assertSee('日付から選ぶ');
    }
}
