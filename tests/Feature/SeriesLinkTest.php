<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * すでにある本番の案件どうしを「連日イベント」として結ぶ（POST /projects/series-link）。
 * 2026-11-12 小沼さん：アサイン表の取込で1日ずつ別々にできた4日間のイベントを、あとから1つにまとめたい。
 *
 * ⚠ 変わるのは結びつき（parent_project_id）だけ。中身には触らない。
 * ⚠ あわせて直したこと：連日イベントの2日目を編集画面で保存すると結びつきが外れていた。
 */
class SeriesLinkTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '登録する人', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function day(string $id, string $date, array $more = []): Project
    {
        return ProjectFactory::new()->create($more + [
            'id' => $id, 'project_name' => '場所貸し', 'client' => 'テスト株式会社',
            'start_date' => $date, 'date_type' => '本番', 'office' => '東京',
        ]);
    }

    public function test_別々の案件を連日イベントとして結べる(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');
        $this->day('P-2', '2027-03-02', ['required_count' => 4]);
        $this->day('P-3', '2027-03-03');

        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-2', 'parent_id' => 'P-1'])
            ->assertOk()->assertJson(['ok' => true, 'root' => 'P-1', 'days' => 2]);
        // 3日目は「2日目」を相手に選んでも、根っこ（1日目）に結ばれる＝まとまりは1つ。
        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-3', 'parent_id' => 'P-2'])
            ->assertOk()->assertJson(['ok' => true, 'root' => 'P-1', 'days' => 3]);

        $this->assertSame('P-1', Project::find('P-2')->parent_project_id);
        $this->assertSame('P-1', Project::find('P-3')->parent_project_id);
        $this->assertNull(Project::find('P-1')->parent_project_id);
        $this->assertSame(4, (int) Project::find('P-2')->required_count, '中身が変わっている');
    }

    public function test_子を持つ案件を結ぶと子も一緒に移る(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');
        $this->day('P-2', '2027-03-02');
        $this->day('P-3', '2027-03-03', ['parent_project_id' => 'P-2']);

        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-2', 'parent_id' => 'P-1'])
            ->assertOk()->assertJson(['days' => 3]);

        $this->assertSame('P-1', Project::find('P-3')->parent_project_id, '子が宙に浮いている');
    }

    public function test_結びつきを外せる・同じまとまりの相手を選んでも壊れない(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');
        $this->day('P-2', '2027-03-02', ['parent_project_id' => 'P-1']);

        // 1日目から2日目を選ぶ＝もう同じまとまり。自分を自分の子にしない。
        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-1', 'parent_id' => 'P-2'])
            ->assertOk()->assertJson(['ok' => true, 'root' => 'P-1', 'days' => 2]);
        $this->assertNull(Project::find('P-1')->parent_project_id);

        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-2', 'parent_id' => ''])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNull(Project::find('P-2')->parent_project_id);
    }

    public function test_リハ日は結べない・相手が本番でないと結べない(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');
        $this->day('P-R', '2027-02-28', ['date_type' => 'リハ日']);

        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-R', 'parent_id' => 'P-1'])->assertStatus(422);
        $this->actingAsPerson($me)->postJson('/projects/series-link', ['id' => 'P-1', 'parent_id' => 'P-R'])->assertStatus(422);
        $this->assertNull(Project::find('P-1')->parent_project_id);
    }

    public function test_連日イベントの2日目を編集画面で保存しても結びつきが残る(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');
        $this->day('P-2', '2027-03-02', ['parent_project_id' => 'P-1']);

        $this->actingAsPerson($me)->post('/project-form', [
            'project_id' => 'P-2',
            'client' => 'テスト株式会社',
            'content_names' => '謎解き',
            'required_count' => '10',
            'start_date' => '2027-03-02',
            'office' => '東京',
            'intent' => 'publish',
        ])->assertRedirect('/projects');

        $this->assertSame('P-1', Project::find('P-2')->parent_project_id, '編集で結びつきが外れた');
    }

    public function test_編集画面に結ぶ欄が出る(): void
    {
        $me = $this->manager();
        $this->day('P-1', '2027-03-01');

        $this->actingAsPerson($me)->get('/project-form?project=P-1')
            ->assertOk()->assertSee('seriesLinkBox', false)->assertSee('/projects/series-link', false);
    }
}
