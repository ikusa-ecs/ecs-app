<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードの「希望者」に、終日〇を出した人がちゃんと出るか（2026-09-03 baba報告
 * 「終日でエントリーしてくれてるスタッフさんが出てないかも」）。
 *
 * ⚠ 調べたら、出ない人が2種類いた。
 *   ① 拠点がちがう人 …… 拠点で絞って見ているとき（一般社員は自拠点のみ）。
 *      これは方針どおり（baba決定 2026-09-03「自分の拠点だけのままでいい」）だが、
 *      **黙って消えると原因にたどり着けない**ので、人数と理由を画面に出すことにした。
 *   ② 退職・無効にした人 …… 出てしまっていた。出さないようにした（baba決定 2026-09-03）。
 *
 * ⚠ 事務所が未設定の人は「東京」で見たときに出る（OfficeScope の決まり）。
 *   ここが変わると、事務所を入れていないスタッフが丸ごと消える。
 */
class BoardAvailWhoShowsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = Carbon::today()->addDays(3);

        ProjectFactory::new()->create([
            'id' => 'P-AV', 'project_name' => 'テスト案件',
            'start_date' => $this->day->format('Y-m-d'),
            'office' => '東京', 'status' => '調整中', 'required_count' => 3,
        ]);
    }

    /** 終日〇（稼働可）を1件入れた人を作る。 */
    private function wants(string $id, string $name, ?string $office, bool $active = true, string $role = 'staff'): Person
    {
        $p = PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => $role,
            'permission' => $role === 'staff' ? 'staff' : 'employee',
            'office' => $office, 'must_onboard' => false, 'active' => $active,
        ]);
        ShiftPreference::create([
            'staff_id' => $id, 'period' => $this->day->format('Y-m'),
            'date' => $this->day->format('Y-m-d'), 'availability' => '稼働可',
        ]);

        return $p;
    }

    private function viewer(string $permission): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-VIEW', 'role' => 'employee', 'permission' => $permission,
            'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
    }

    /** 自拠点のスタッフ・事務所が未設定のスタッフは、終日〇なら希望者に出る。 */
    public function test_all_day_staff_of_my_office_show_up(): void
    {
        $this->wants('S-TKY', 'トウキョウ花子', '東京');
        $this->wants('S-NUL', 'ジムショナシ次郎', null);

        $html = $this->actingAsPerson($this->viewer('employee'))->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('S-TKY', $html, '終日〇の自拠点スタッフが希望者に出ていません。');
        $this->assertStringContainsString('S-NUL', $html, '事務所が未設定のスタッフが希望者から消えています。');
    }

    /** 退職・無効にした人は出さない（2026-09-03 baba決定）。 */
    public function test_a_retired_person_does_not_show_up(): void
    {
        $this->wants('S-OUT', 'タイショク三郎', '東京', false);

        $html = $this->actingAsPerson($this->viewer('employee'))->get('/assign')->assertOk()->getContent();

        $this->assertStringNotContainsString('S-OUT', $html, '退職にした人が希望者に出ています。');
    }

    /**
     * 拠点がちがう人は出さないが、⚠ 人数と理由は画面に出す。
     * 黙って消すと「終日〇を出したのに出てこない」の原因にたどり着けない。
     */
    public function test_other_office_people_are_hidden_but_counted(): void
    {
        $this->wants('S-OSA', 'オオサカ四郎', '大阪');

        $html = $this->actingAsPerson($this->viewer('employee'))->get('/assign')->assertOk()->getContent();

        $this->assertStringNotContainsString('S-OSA', $html, '拠点で絞っているのに他拠点の人が出ています。');
        $this->assertStringContainsString('ECS_BOARD_AVAIL_HIDDEN', $html);
        $this->assertStringContainsString('ほかの拠点のため ${hiddenN}名を出していません', $html, '隠した理由の表示が消えています。');
    }

    /** 全拠点で見る人（管理者以上）には、他拠点の人も出る。 */
    public function test_an_admin_sees_every_office(): void
    {
        $this->wants('S-OSA', 'オオサカ四郎', '大阪');

        $html = $this->actingAsPerson($this->viewer('admin'))->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('S-OSA', $html, '全拠点で見ているのに他拠点の人が出ていません。');
    }

    /**
     * 社員も、拠点で絞ったときとそうでないときで同じ決まりで絞ること。
     * ⚠ 以前はスタッフだけを絞りの対象にしていたので、拠点で絞ると社員が丸ごと消え、
     *   全拠点だと出る＝**見る人によって違う**状態だった。
     */
    public function test_employees_follow_the_same_office_rule(): void
    {
        $this->wants('E-TKY', 'シャインA', '東京', true, 'employee');

        $html = $this->actingAsPerson($this->viewer('employee'))->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('E-TKY', $html, '自拠点の社員が希望者（たたんだ中）から消えています。');
    }
}
