<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「その案件限りのコンテンツ（単発）」の扱い。
 *
 * 案件名にコンテンツ台帳に無い名前を入れたとき、
 * ・そのまま入れると → 台帳に新しいコンテンツとして登録される（これまでどおり）
 * ・「この案件だけで使う」を選ぶと → 台帳には登録されず、名前は案件に残る
 * という2通りを選べるようにした（社内要望・2026-08-04）。
 */
class OneOffContentTest extends TestCase
{
    use RefreshDatabase;

    /** 「この案件だけで使う」を選んだコンテンツは、台帳に登録されない。 */
    public function test_one_off_content_is_not_added_to_the_master(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'          => 'オリジナル脱出ゲーム',
            'oneoff_content_names'   => 'オリジナル脱出ゲーム',
            'start_date'             => '2026-09-10',
            'required_count'         => '8',
            'intent'                 => 'publish',
        ])->assertRedirect('/projects');

        // 台帳は増えない。
        $this->assertDatabaseMissing('contents', ['content_name' => 'オリジナル脱出ゲーム']);

        // 案件名としては残る＝画面から見えなくならない。
        $project = Project::where('project_name', 'オリジナル脱出ゲーム')->first();
        $this->assertNotNull($project);
        $this->assertSame(['オリジナル脱出ゲーム'], $project->content_names);
        $this->assertSame([], $project->content_ids);   // 台帳の番号は持たない
    }

    /** 「この案件だけで使う」を選ばなければ、これまでどおり台帳に登録される。 */
    public function test_new_content_is_still_added_to_the_master_when_not_marked_one_off(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'  => '新定番ゲーム',
            'start_date'     => '2026-09-11',
            'required_count' => '8',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $this->assertDatabaseHas('contents', ['content_name' => '新定番ゲーム']);

        $project = Project::where('project_name', '新定番ゲーム')->first();
        $this->assertCount(1, $project->content_ids);   // 台帳の番号を持つ
    }

    /** 台帳にあるコンテンツと単発コンテンツを混ぜても、それぞれ正しく分かれる。 */
    public function test_master_content_and_one_off_content_can_be_mixed(): void
    {
        Content::create(['id' => 'C-800', 'content_name' => '水合戦', 'active' => true]);

        $this->actingAsPerson(PersonFactory::new()->create())->post('/project-form', [
            'content_names'        => '水合戦,その場かぎりの余興',
            'oneoff_content_names' => 'その場かぎりの余興',
            'start_date'           => '2026-09-12',
            'required_count'       => '12',
            'intent'               => 'publish',
        ])->assertRedirect('/projects');

        $this->assertDatabaseMissing('contents', ['content_name' => 'その場かぎりの余興']);

        $project = Project::where('project_name', '水合戦・その場かぎりの余興')->first();
        $this->assertNotNull($project);
        $this->assertSame(['水合戦', 'その場かぎりの余興'], $project->content_names);
        $this->assertSame(['C-800'], $project->content_ids);   // 台帳にある方だけ番号を持つ
    }

    /**
     * 単発コンテンツの案件を編集画面で開くと、名前が消えずに戻ってくる。
     * （台帳の番号を持たないので、案件側に残した名前から復元できるかの確認）
     */
    public function test_editing_a_project_keeps_the_one_off_content_name(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'        => 'オリジナル脱出ゲーム',
            'oneoff_content_names' => 'オリジナル脱出ゲーム',
            'start_date'           => '2026-09-13',
            'required_count'       => '8',
            'intent'               => 'publish',
        ]);

        $project = Project::where('project_name', 'オリジナル脱出ゲーム')->firstOrFail();

        $res = $this->actingAsPerson($employee)->get('/project-form?project=' . $project->id);
        $res->assertOk();

        $edit = $res->original->getData()['editProject'];
        $this->assertSame(['オリジナル脱出ゲーム'], $edit['content_names']);
        // 「単発」の印も復元される＝保存し直しても台帳に登録されない。
        $this->assertSame(['オリジナル脱出ゲーム'], $edit['oneoff_content_names']);
    }

    /** 古い案件（入力された名前を持っていない）は、これまでどおり台帳の番号から名前を戻す。 */
    public function test_older_projects_without_stored_names_still_show_their_content(): void
    {
        Content::create(['id' => 'C-801', 'content_name' => '運動会', 'active' => true]);

        $project = Project::create([
            'id'           => 'P-2026-9001',
            'project_name' => '運動会',
            'content_ids'  => ['C-801'],
            'content_names' => null,      // この列ができる前に登録された案件を想定
            'start_date'   => '2026-09-14',
        ]);

        $res = $this->actingAsPerson(PersonFactory::new()->create())
            ->get('/project-form?project=' . $project->id);

        $res->assertOk();
        $edit = $res->original->getData()['editProject'];
        $this->assertSame(['運動会'], $edit['content_names']);
        $this->assertSame([], $edit['oneoff_content_names']);   // 台帳にあるので単発ではない
    }
}
