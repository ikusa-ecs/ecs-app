<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「🔒 この人数で足りている」で締めた募集が、画面を開き直しても外れないこと
 * （2026-09-10 baba報告「ボタンを押して確定にしても、更新を押したら外れてしまう」）。
 */
class CloseRecruitStaysTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京']);
    }

    private function publishedProject()
    {
        return ProjectFactory::new()->published()->create([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'required_count' => 8,
            'is_recruiting' => true,
            'status' => '調整中',
        ]);
    }

    /** ① ボタンの保存そのもの＝ DB の is_recruiting が false になる。 */
    public function test_closing_the_recruit_is_saved(): void
    {
        $p = $this->publishedProject();

        $this->actingAsPerson($this->manager())
            ->postJson('/projects/cells', ['id' => $p->id, 'recruit' => false])
            ->assertOk();

        $this->assertFalse((bool) $p->fresh()->is_recruiting, '締めたのに保存されていません');
    }

    /** ② 開き直しても締めたままに見える（日別ボードのカード）。 */
    public function test_the_board_still_shows_it_closed_after_reload(): void
    {
        $p = $this->publishedProject();
        $me = $this->manager();

        $this->actingAsPerson($me)
            ->postJson('/projects/cells', ['id' => $p->id, 'recruit' => false])
            ->assertOk();

        $card = collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $p->id);

        $this->assertNotNull($card, '日別ボードにこの案件が出ていません');
        $this->assertFalse($card['recruit'], '開き直したら募集が再開してしまっています');
    }

    /**
     * ③ 締めたあとに「✓ 確定にする」を押しても、締めたままであること。
     * ⚠ 確定と募集を締めるのは**別の意思表示**（2026-09-01 baba）。
     */
    public function test_confirming_the_case_does_not_reopen_the_recruit(): void
    {
        $p = $this->publishedProject();
        $me = $this->manager();

        $this->actingAsPerson($me)->postJson('/projects/cells', ['id' => $p->id, 'recruit' => false])->assertOk();
        $this->actingAsPerson($me)->postJson('/projects/cells', ['id' => $p->id, 'status' => '確定'])->assertOk();

        $fresh = $p->fresh();
        $this->assertSame('確定', $fresh->status);
        $this->assertFalse((bool) $fresh->is_recruiting, '確定にしたら募集が再開してしまっています');
    }

    /**
     * ④ 日別ボードの画面が「締めたか（recruit）」を詰め替えていること。
     *
     * ⚠ 2026-09-10 の不具合はここだった。サーバーは `recruit: false` を渡していたのに、
     *   画面のJSがカードを作り直すときに **recruit を写し忘れて**いたため、
     *   `bRecruit()`（無ければ募集中とみなす）が毎回 true になり、
     *   **開き直すたびに「募集中 あと◯名」に戻って見えた**（保存はできていた）。
     *   この行を消すと同じことが起きるので、見張りを置く。
     */
    public function test_the_board_view_keeps_recruit_in_its_mapping(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString(
            'recruit:c.recruit',
            $html,
            '画面の詰め替えから recruit が消えると、募集を締めても開き直すたびに「募集中」に戻ります'
        );
    }

    /**
     * ⑤ 締めた案件を案件登録画面（編集）から保存し直しても、締めたままであること。
     * ⚠ 案件の保存は「募集しない」チェックの有無で is_recruiting を決めるので、
     *   画面がその状態を送り忘れると、ここで勝手に募集が再開してしまう。
     */
    public function test_saving_the_project_form_keeps_it_closed(): void
    {
        $p = $this->publishedProject();
        $me = $this->manager();

        $this->actingAsPerson($me)->postJson('/projects/cells', ['id' => $p->id, 'recruit' => false])->assertOk();

        // 案件登録画面（編集）が送るのと同じ形＝「募集しない」チェックが入った状態。
        $this->actingAsPerson($me)->post('/projects', [
            'editId' => $p->id,
            'projectName' => $p->project_name,
            'eventDate' => $p->start_date->format('Y-m-d'),
            'noRecruit' => 'on',
        ]);

        $this->assertFalse((bool) $p->fresh()->is_recruiting, '案件を保存し直したら募集が再開してしまっています');
    }
}
