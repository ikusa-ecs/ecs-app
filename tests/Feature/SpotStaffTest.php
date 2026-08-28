<?php

namespace Tests\Feature;

use App\Mail\LoginInviteMail;
use App\Models\Person;
use App\Models\Project;
use App\Support\LoginInvite;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 臨時スタッフ（2026-08-25 baba要望）。
 *
 * インターンで今月だけ来る方、誰かの知り合いの助っ人など、名簿に無い人を
 * アサイン画面からその場で足せるようにした。
 *
 * 【決め方（baba選択）】
 *  ・アサインに名前を文字で書くのではなく、**名簿に足す**。
 *    アサインは「案件 × 名簿の人 × 日」で全画面がつながっているので、文字で書く形にすると
 *    その人だけ出勤数にも履歴にも入らなくなるため。
 *  ・**出勤数・集計にも数える**（ふつうのスタッフと同じ扱い）。
 *  ・ログインはしない（メール・パスワードを持たない）。
 */
class SpotStaffTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function employee(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-002', 'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 管理者が、名簿に無い人を臨時スタッフとして足せる。 */
    public function test_manager_can_add_a_spot_staff(): void
    {
        $res = $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '田中 花子'])
            ->assertOk();

        $id = $res->json('id');
        $this->assertNotNull($id);

        $p = Person::findOrFail($id);
        $this->assertSame('田中花子', $p->name, 'スタッフの氏名は空白を詰めて保存する');
        $this->assertSame('staff', $p->role);
        $this->assertSame('staff', $p->permission);
        $this->assertTrue((bool) $p->is_spot, '臨時の印が付くこと');
        $this->assertTrue((bool) $p->active);
        $this->assertSame('東京', $p->office, '足した人の拠点で入ること');
        // ログインしない＝メールもパスワードも持たない。初回設定にも通さない。
        $this->assertNull($p->email);
        $this->assertNull($p->password);
        $this->assertFalse((bool) $p->must_onboard);
    }

    /** スタッフ番号（S-###）は名簿登録・CSV取込と同じ採番の続きになる。 */
    public function test_id_continues_the_staff_numbering(): void
    {
        PersonFactory::new()->create(['id' => 'S-007', 'role' => 'staff', 'permission' => 'staff']);

        $id = $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '助っ人 一郎'])
            ->assertOk()->json('id');

        $this->assertSame('S-008', $id);
    }

    /** すでに名簿にいる人は作らない（同姓同名を勝手に増やさない）。 */
    public function test_existing_person_is_not_duplicated(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-050', 'name' => '鈴木 彩', 'role' => 'staff', 'permission' => 'staff', 'office' => '東北',
        ]);

        $res = $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '鈴木彩'])   // 空白の有無は無視して見る
            ->assertStatus(422);

        $this->assertFalse($res->json('ok'));
        $this->assertStringContainsString('S-050', $res->json('message'));
        $this->assertSame(2, Person::count(), '増えていないこと');
    }

    /** 名簿への登録は管理者以上（2026-07-02 確定の権限ルール）。 */
    public function test_employee_cannot_add_a_spot_staff(): void
    {
        $this->actingAsPerson($this->employee())
            ->postJson('/people/spot', ['name' => '田中 花子'])
            ->assertStatus(403);

        $this->assertNull(Person::where('name', '田中 花子')->first());
    }

    /** 臨時スタッフにはログイン案内メールを送らない。 */
    public function test_login_invite_is_refused_for_spot_staff(): void
    {
        Mail::fake();
        $spot = PersonFactory::new()->create([
            'id' => 'S-060', 'name' => '助っ人 二郎', 'role' => 'staff', 'permission' => 'staff',
            'is_spot' => true, 'email' => 'help@example.com', 'active' => true,
        ]);

        $result = LoginInvite::send($spot);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('臨時', $result['message']);
        Mail::assertNotSent(LoginInviteMail::class);
    }

    /** アサイン画面に候補として並び、「臨時」と分かる。 */
    public function test_spot_staff_appears_on_the_assign_screen(): void
    {
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => '2026-09-01',
        ]);
        PersonFactory::new()->create([
            'id' => 'S-070', 'name' => '助っ人 三郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($this->manager())
            ->get('/project-assign?project='.urlencode($project->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('助っ人三郎', $html);
        $this->assertStringContainsString('臨時', $html);
    }

    /** 足した直後は、その人にチェックが入った状態で開く（保存で消えないように）。 */
    public function test_added_person_is_pre_checked(): void
    {
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => '2026-09-01',
        ]);
        PersonFactory::new()->create([
            'id' => 'S-071', 'name' => '助っ人 四郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false,
        ]);

        $html = $this->actingAsPerson($this->manager())
            ->get('/project-assign?project='.urlencode($project->id).'&added=S-071')
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="S-071" checked', $html);
        $this->assertStringContainsString('臨時スタッフとして名簿に足しました', $html);
    }

    /** 足す受け口は管理者以上にだけ出す。 */
    public function test_add_box_is_only_shown_to_managers(): void
    {
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-01']);
        $url = '/project-assign?project='.urlencode($project->id);

        $forManager = $this->actingAsPerson($this->manager())->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('id="spotBox"', $forManager);

        $forEmployee = $this->actingAsPerson($this->employee())->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('id="spotBox"', $forEmployee);
    }

    /** 臨時スタッフでも、ふつうにアサインできて出勤数に数えられる。 */
    public function test_spot_staff_can_be_assigned_like_anyone_else(): void
    {
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => '2026-09-01',
        ]);
        PersonFactory::new()->create([
            'id' => 'S-072', 'name' => '助っ人 五郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false,
        ]);

        $this->actingAsPerson($this->manager())->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '仮',            // 声掛け中は「仮」で置いておける
            'staff_ids'  => ['S-072'],
            'role'       => ['S-072' => 'OP'],
        ]);

        $this->assertDatabaseHas('assignments', [
            'project_id' => $project->id,
            'staff_id'   => 'S-072',
            'role'       => 'OP',
            'status'     => '仮',
        ]);
    }

    /** 名簿では「臨時（ログインなし）」と分かり、絞り込みでも出し分けられる。 */
    public function test_roster_marks_spot_staff(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-073', 'name' => '助っ人 六郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false,
        ]);

        $res = $this->actingAsPerson($this->manager())->get('/staff')->assertOk();

        $row = collect($res->viewData('people'))->firstWhere('id', 'S-073');
        $this->assertTrue($row['spot'], '名簿に臨時の印が渡ること');

        $html = $res->getContent();
        $this->assertStringContainsString('臨時スタッフのみ', $html, '絞り込みに臨時が増えていること');
        $this->assertStringContainsString('臨時スタッフを除く', $html);
    }

    /** 名前が空・長すぎるものは作らない。 */
    public function test_bad_names_are_refused(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->postJson('/people/spot', ['name' => ''])->assertStatus(422);
        $this->actingAsPerson($me)->postJson('/people/spot', ['name' => str_repeat('あ', 51)])
            ->assertStatus(422);

        $this->assertSame(1, Person::count(), '自分だけ');
    }

    /** 拠点を指定して足せる（他拠点の案件をアサインするとき用）。 */
    public function test_office_can_be_given(): void
    {
        $id = $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '東北の助っ人', 'office' => '東北'])
            ->assertOk()->json('id');

        $this->assertSame('東北', Person::findOrFail($id)->office);
    }

    /** 知らない拠点名が来ても、そのまま入れずに自分の拠点にする。 */
    public function test_unknown_office_falls_back_to_own_office(): void
    {
        $id = $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '謎の助っ人', 'office' => 'どこか'])
            ->assertOk()->json('id');

        $this->assertSame('東京', Person::findOrFail($id)->office);
    }

    /** 案件が無くても名簿には足せる（画面から呼ぶが、登録そのものは案件と関係ない）。 */
    public function test_adding_does_not_touch_projects(): void
    {
        $this->actingAsPerson($this->manager())
            ->postJson('/people/spot', ['name' => '助っ人 七郎'])->assertOk();

        $this->assertSame(0, Project::count());
    }

    // ── 臨時の解除（2026-08-28 baba要望）─────────────────────────────
    // 「臨時で入ってもらった方のメアドが分かって、正式に登録したい」で
    // ふつうに登録し直すと名簿が二重になる（実際に起きた）。印だけ外せるようにした。

    /** 臨時を解除すると印が外れ、ログイン案内が送れるようになる。 */
    public function test_manager_can_release_a_spot_staff(): void
    {
        Mail::fake();
        PersonFactory::new()->create([
            'id' => 'S-080', 'name' => '助っ人 八郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false, 'email' => null,
        ]);

        $this->actingAsPerson($this->manager())
            ->postJson('/people/S-080/unspot')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $p = Person::findOrFail('S-080');
        $this->assertFalse((bool) $p->is_spot, '臨時の印が外れること');

        // 外したあとは、ログイン案内メールを送れる（臨時のあいだは断られていた）。
        $p->email = 'help8@example.com';
        $p->save();
        $this->assertTrue(LoginInvite::send($p->fresh())['ok']);
    }

    /** 解除しても、その人のアサインの記録は消えない（作り直さないのが肝）。 */
    public function test_release_keeps_the_assignment_records(): void
    {
        $project = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-09-01']);
        PersonFactory::new()->create([
            'id' => 'S-081', 'name' => '助っ人 九郎', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'is_spot' => true, 'must_onboard' => false,
        ]);
        $manager = $this->manager();   // ⚠ manager() は毎回 E-001 を作る＝1回だけ呼ぶ
        $this->actingAsPerson($manager)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status' => '仮',
            'staff_ids' => ['S-081'],
            'role' => ['S-081' => 'OP'],
        ]);
        $before = \DB::table('assignments')->where('staff_id', 'S-081')->count();
        $this->assertGreaterThan(0, $before);

        $this->actingAsPerson($manager)->postJson('/people/S-081/unspot')->assertOk();

        $this->assertSame($before, \DB::table('assignments')->where('staff_id', 'S-081')->count());
        $this->assertSame('S-081', Person::findOrFail('S-081')->id, '同じ人のままであること');
    }

    /** もともと臨時でない人には効かない（押し間違いを知らせる）。 */
    public function test_release_is_refused_for_a_normal_staff(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-082', 'name' => 'ふつう 太郎', 'role' => 'staff', 'permission' => 'staff',
            'is_spot' => false, 'must_onboard' => false,
        ]);

        $this->actingAsPerson($this->manager())
            ->postJson('/people/S-082/unspot')
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    /** 解除できるのは管理者以上（名簿を触る操作なので、足すのと同じ権限）。 */
    public function test_employee_cannot_release_a_spot_staff(): void
    {
        PersonFactory::new()->create([
            'id' => 'S-083', 'name' => '助っ人 十郎', 'role' => 'staff', 'permission' => 'staff',
            'is_spot' => true, 'must_onboard' => false,
        ]);

        $this->actingAsPerson($this->employee())
            ->postJson('/people/S-083/unspot')
            ->assertStatus(403);

        $this->assertTrue((bool) Person::findOrFail('S-083')->is_spot, '印が残っていること');
    }

    /** 名簿の詳細に「臨時を解除」のボタンが出る（臨時の人にだけ）。 */
    public function test_release_button_is_shown_only_for_spot_staff(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/staff')->assertOk()->getContent();

        // 行はJSで作るので、押したときの入口（data-unspot-id）が画面に含まれていること。
        $this->assertStringContainsString('data-unspot-id', $html);
        $this->assertStringContainsString('臨時を解除して正式スタッフにする', $html);
    }
}
