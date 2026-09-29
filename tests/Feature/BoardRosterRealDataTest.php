<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードの「名簿から追加…」は、DBの名簿から作る（2026-08-24）。
 *
 * ⚠ 何が起きていたか（コード全体の棚卸しで発見）
 *   このプルダウンは凍結モック /ecs/data/people.js の ECS_PEOPLE をそのまま並べていた。
 *   画面には架空の名前（田中 健一 など）が出るのに、選ぶと「その架空の人のID」で
 *   本物のアサインが保存される。ECSが自動で振る社員番号も E-001 形式なので、
 *   実在する別人のアサインが作られる＝人の取り違えが起きる状態だった。
 *
 * あわせて「自分の案件」印が in_array('baba', ...) と名前直書きで、
 * 誰がログインしても baba さんの案件にだけ印が付いていた。
 */
class BoardRosterRealDataTest extends TestCase
{
    use RefreshDatabase;

    private function emp(array $attrs = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false, 'active' => true,
        ], $attrs));
    }

    /** 凍結モックの名簿ファイルを読み込んでいないこと。 */
    public function test_board_does_not_load_frozen_people_mock(): void
    {
        $html = $this->actingAsPerson($this->emp(['id' => 'E-001']))
            ->get('/assign')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script src="/ecs/data/people.js"', $html);
    }

    /** 名簿はDBの人が並ぶ（社員もスタッフも）。 */
    public function test_roster_comes_from_database(): void
    {
        $me = $this->emp(['id' => 'E-001', 'name' => '社員のひと', 'name_kana' => 'しゃいんのひと']);
        PersonFactory::new()->create([
            'id' => 'S-001', 'name' => 'スタッフのひと', 'name_kana' => 'すたっふのひと',
            'role' => 'staff', 'permission' => 'staff', 'office' => '東京',
            'must_onboard' => false, 'active' => true,
        ]);

        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();

        // ECS_ROSTER にDBの人が入っていること。
        $this->assertStringContainsString('"id":"E-001"', $html);
        $this->assertStringContainsString('"id":"S-001"', $html);
        $this->assertStringContainsString('ECS_ROSTER', $html);
    }

    /**
     * 社員は他拠点の人も出す（新入社員が東京で研修に入るなど・2026-09-29 baba要望）。
     * スタッフは今までどおり、いま見ている拠点の人だけ。
     */
    public function test_other_office_employee_is_offered_but_staff_is_not(): void
    {
        $me = $this->emp(['id' => 'E-001']);
        PersonFactory::new()->create(['id' => 'E-OSK', 'role' => 'employee', 'office' => '大阪', 'active' => true]);
        PersonFactory::new()->create(['id' => 'S-OSK', 'role' => 'staff', 'permission' => 'staff', 'office' => '大阪', 'active' => true]);

        $roster = collect($this->actingAsPerson($me)->get('/assign?office=東京')->assertOk()->viewData('roster'))
            ->keyBy('id');

        $this->assertTrue($roster['E-OSK']['otherOffice'], '大阪の社員は「他拠点」として出る');
        $this->assertSame('大阪', $roster['E-OSK']['office']);
        $this->assertFalse($roster['E-001']['otherOffice']);
        $this->assertFalse($roster->has('S-OSK'), '他拠点のスタッフは出さない');
    }

    /** 退職した人は候補に出さない。 */
    public function test_retired_person_is_not_offered(): void
    {
        $me = $this->emp(['id' => 'E-001']);
        PersonFactory::new()->create([
            'id' => 'S-900', 'name' => '辞めたひと', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false, 'active' => false,
        ]);

        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();

        $this->assertStringNotContainsString('"id":"S-900"', $html);
    }

    /** 「自分の案件」印は、ログインしている本人の案件に付く（baba 固定ではない）。 */
    public function test_mine_flag_follows_logged_in_user(): void
    {
        $me = $this->emp(['id' => 'E-010', 'name' => '営業のわたし']);

        // 自分が営業担当の案件と、他人が営業担当の案件を作る。
        ProjectFactory::new()->create([
            'id' => 'P-MINE', 'office' => '東京',
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'status' => '未着手', 'sales_owners' => ['営業のわたし'],
        ]);
        ProjectFactory::new()->create([
            'id' => 'P-OTHER', 'office' => '東京',
            'start_date' => Carbon::today()->addDays(4)->format('Y-m-d'),
            'status' => '未着手', 'sales_owners' => ['baba'],
        ]);

        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();

        // 自分の案件は mine=true、他人の案件は mine=false。
        $this->assertMatchesRegularExpression('/"id":"P-MINE".{0,4000}?"mine":true/s', $html);
        $this->assertMatchesRegularExpression('/"id":"P-OTHER".{0,4000}?"mine":false/s', $html);
    }

    /** マイページの「ログアウト」が本当にログアウトする（POST /logout を送る）。 */
    public function test_mypage_logout_posts_to_logout(): void
    {
        $html = $this->actingAsPerson($this->emp(['id' => 'E-001']))
            ->get('/mypage')->assertOk()->getContent();

        // ⚠ 以前は location.href = '/' だけで、セッションが残っていた。
        $this->assertStringContainsString("f.action = '/logout'", $html);
        $this->assertStringNotContainsString("location.href = '/';", $html);
    }
}
