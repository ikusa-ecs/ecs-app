<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー時のコメント（applications.note）。2026-08-21 baba要望。
 *
 * これまで＝コメントは「エントリーする」を押した瞬間だけ送っていたので、
 * **エントリーしたあとに書いたコメントはどこにも保存されなかった**（画面にも保存ボタンが無かった）。
 * これから＝保存ボタンで、応募したままコメントだけ書き換えられる。
 * そのコメントは、アサインする画面（日別ボード・案件別アサイン・エントリー一覧・エントリー新着）に出る。
 */
class EntryCommentTest extends TestCase
{
    use RefreshDatabase;

    private function staffAndProject(): array
    {
        $staff = PersonFactory::new()->staff()->create(['name' => '応募したスタッフ', 'office' => '東京']);
        $p = ProjectFactory::new()->published()->create([
            'office'     => '東京',
            'start_date' => Carbon::today()->addDays(4)->format('Y-m-d'),
        ]);

        return [$staff, $p];
    }

    /** あとから書いたコメントも保存される（応募は取り消されない）。 */
    public function test_comment_can_be_saved_after_applying(): void
    {
        [$staff, $p] = $this->staffAndProject();

        // まず応募（コメント無し）
        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/entry', ['project_id' => $p->id, 'action' => 'apply'])
            ->assertOk();

        // あとからコメントだけ保存
        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/entry', [
                'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望',
                'note' => '午後からなら入れます',
            ])
            ->assertOk()
            ->assertJson(['saved' => true, 'applied' => true]);

        $app = Application::where('staff_id', $staff->id)->where('project_id', $p->id)->first();
        $this->assertNotNull($app, '応募は残っている');
        $this->assertSame('午後からなら入れます', $app->note);
        $this->assertSame(1, Application::count(), '同じ人×同じ案件で行が増えない');
    }

    /** そのコメントが日別ボードの応募者データに入っている（アサインする人に見える）。 */
    public function test_comment_reaches_the_day_board(): void
    {
        [$staff, $p] = $this->staffAndProject();
        Application::create([
            'staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望',
            'note' => '午後からなら入れます',
        ]);

        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $cases = collect(
            $this->actingAsPerson($emp)->get('/assign')->assertOk()->original->getData()['boardCases']
        );

        $applicant = collect($cases->firstWhere('id', $p->id)['applicants'])->firstWhere('id', $staff->id);
        $this->assertSame('午後からなら入れます', $applicant['note']);
    }

    /** 案件別アサインにも本人の一言が届く。 */
    public function test_comment_reaches_the_project_assign_screen(): void
    {
        [$staff, $p] = $this->staffAndProject();
        Application::create([
            'staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望', 'note' => '駅から歩けます',
        ]);

        $emp = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
        $list = collect(
            $this->actingAsPerson($emp)->get('/project-assign?project=' . urlencode($p->id))
                ->assertOk()->original->getData()['staff']
        )->keyBy('id');

        $this->assertSame('駅から歩けます', $list[$staff->id]['entryNote']);
    }
}
