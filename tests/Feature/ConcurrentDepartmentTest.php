<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Support\ChatworkIds;
use App\Support\Departments;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 所属の兼務と、チャットワークIDの登録（2026-08-24 baba要望）。
 *
 * 【兼務】所属を兼ねている人がいる。
 *   ・department  ＝主な所属（1つ）。部署別の集計はこれで1回だけ数える
 *                   （両方に数えると合計が人数と合わなくなるため）。
 *   ・departments ＝兼務を含む所属すべて。表示・絞り込み・イベプラ判定はこちらを見る。
 *
 * 【チャットワークID】リマインドの宛先。これまで氏名の突き合わせだけで決めていたため、
 *   表記ゆれで外れるとその人にタスクが飛ばなかった。名簿の登録があればそれを優先する。
 */
class ConcurrentDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private function emp(array $attrs = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ], $attrs));
    }

    /** 兼務を含めた所属の一覧は、主な所属が先頭に来る。 */
    public function test_department_list_puts_main_first(): void
    {
        $p = $this->emp([
            'id' => 'E-801', 'department' => 'ARENA',
            'departments' => ['イベプラ', 'ARENA'],
        ]);

        $this->assertSame(['ARENA', 'イベプラ'], $p->departmentList());
    }

    /** 兼務のチェックを外して保存されても、主な所属は必ず含まれる。 */
    public function test_main_department_is_always_included(): void
    {
        $this->assertSame(['ARENA'], Departments::normalize('ARENA', []));
        $this->assertSame(['ARENA', 'イベプラ'], Departments::normalize('ARENA', ['イベプラ']));
        // 一覧に無い名前（タイポ・古い部署名）は捨てる。
        $this->assertSame(['イベプラ'], Departments::normalize('イベプラ', ['存在しない部署']));
        // 何も残らなければ未設定（null）。
        $this->assertNull(Departments::normalize(null, []));
    }

    /** 兼務が無い人は、主な所属1つだけとして扱う（departments 未入力でも動く）。 */
    public function test_works_without_departments_column(): void
    {
        $p = $this->emp(['id' => 'E-802', 'department' => 'セールス', 'departments' => null]);

        $this->assertSame(['セールス'], $p->departmentList());
        $this->assertTrue($p->hasDepartment('セールス'));
        $this->assertFalse($p->hasDepartment('イベプラ'));
    }

    /** 兼務でイベプラに入っている人も「イベプラ」として扱う。 */
    public function test_has_department_sees_concurrent_roles(): void
    {
        $p = $this->emp([
            'id' => 'E-803', 'department' => '経営管理',
            'departments' => ['経営管理', 'イベプラ'],
        ]);

        $this->assertTrue($p->hasDepartment(Departments::PLANNER));
    }

    /** D決め画面で、兼務でイベプラの人も既定表示（planner）の対象になる。 */
    public function test_director_screen_treats_concurrent_planner_as_planner(): void
    {
        $me = $this->emp([
            'id' => 'E-804', 'name' => '兼務の人', 'permission' => 'manager',
            'department' => 'ARENA', 'departments' => ['ARENA', 'イベプラ'],
        ]);

        $html = $this->actingAsPerson($me)->get('/assign-director')->assertOk()->getContent();

        $this->assertStringContainsString('"planner":true', $html);
    }

    /** D／SD のプルダウンで、兼務でイベプラの人も先頭グループに入る。 */
    public function test_picker_lifts_concurrent_planner(): void
    {
        // 主な所属はARENAだが、イベプラを兼務している人。
        $this->emp([
            'id' => 'E-811', 'name' => '兼務', 'name_kana' => 'あけんむ',
            'department' => 'ARENA', 'departments' => ['ARENA', 'イベプラ'],
        ]);
        // イベプラでない人（先頭グループに入らないはず）。
        $this->emp([
            'id' => 'E-812', 'name' => '経営', 'name_kana' => 'あけいえい',
            'department' => '経営管理', 'departments' => ['経営管理'],
        ]);
        $me = $this->emp(['id' => 'E-813', 'name' => '自分', 'name_kana' => 'んじぶん', 'department' => 'イベプラ']);

        $html = $this->actingAsPerson($me)->get('/projects')->assertOk()->getContent();

        $kenmu = strpos($html, '"id":"E-811"');
        $keiei = strpos($html, '"id":"E-812"');
        $this->assertNotFalse($kenmu);
        $this->assertNotFalse($keiei);
        // ふりがなでは「あけいえい」が先だが、兼務のイベプラが先頭グループなので勝つ。
        $this->assertTrue($kenmu < $keiei, '兼務でイベプラの人が先頭グループに入ること');
    }

    /** チャットワークIDは名簿の登録が優先される（氏名の突き合わせより強い）。 */
    public function test_registered_chatwork_id_wins_over_name_match(): void
    {
        $this->emp(['id' => 'E-821', 'name' => '山田 太郎', 'chatwork_id' => '111']);

        $registered = ChatworkIds::fromPeople();

        // 名前は空白を落として突き合わせる（表記ゆれを吸収）。
        $this->assertSame('111', $registered[ChatworkIds::normName('山田　太郎')] ?? null);

        // ルームメンバー側に違うIDがあっても、名簿の登録が勝つ。
        $merged = ChatworkIds::merge($registered, ['山田太郎' => '999', '別の人' => '222']);
        $this->assertSame('111', $merged['山田太郎']);
        // 名簿に登録が無い人は、今までどおりルームメンバーの照合で埋まる。
        $this->assertSame('222', $merged['別の人']);
    }

    /** チャットワークIDが未登録の人は辞書に入らない（穴埋めに回る）。 */
    public function test_people_without_chatwork_id_are_skipped(): void
    {
        $this->emp(['id' => 'E-822', 'name' => 'ID未登録', 'chatwork_id' => null]);

        $this->assertArrayNotHasKey('ID未登録', ChatworkIds::fromPeople());
    }

    /** マイプロフィールから兼務とチャットワークIDが保存できる。 */
    public function test_profile_saves_concurrent_departments_and_chatwork_id(): void
    {
        $me = $this->emp(['id' => 'E-830', 'department' => null, 'departments' => null]);

        $this->actingAsPerson($me)->post('/profile', [
            'name' => $me->name,
            'department' => 'ARENA',
            'departments' => ['イベプラ'],
            'chatwork_id' => '1234567',
        ])->assertRedirect();

        $fresh = $me->fresh();
        $this->assertSame('ARENA', $fresh->department);
        $this->assertSame(['ARENA', 'イベプラ'], $fresh->departments);
        $this->assertSame('1234567', $fresh->chatwork_id);
    }

    /** チャットワークIDに数字以外を入れたら弾く。 */
    public function test_profile_rejects_non_numeric_chatwork_id(): void
    {
        $me = $this->emp(['id' => 'E-831']);

        $this->actingAsPerson($me)->post('/profile', [
            'name' => $me->name,
            'chatwork_id' => 'abc-123',
        ])->assertSessionHasErrors('chatwork_id');
    }
}
