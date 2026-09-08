<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日別ボードから運営人数（必要人数）を直せるようにした（2026-09-08 baba要望）。
 *
 * 【なぜ】人数はお客様と話して当日近くまで動く。ボードは「あと◯名」を見て判断する場所なので、
 *   ここで直せないと公開ボードや案件登録まで往復することになる。
 *
 * ⚠ 保存の入口は**増やさない**＝公開ボードと同じ `POST /assign-publish/count`。
 *   入口を増やすと画面によって違う人数が入る（この repo で何度も起きている事故）。
 */
class BoardEditNeedTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京', 'must_onboard' => false]);
    }

    /** 日別ボードに「✎ 人数」のボタンと保存先が出ている。 */
    public function test_board_has_the_edit_button(): void
    {
        ProjectFactory::new()->create([
            'start_date' => now()->addDays(3)->format('Y-m-d'), 'required_count' => 8, 'office' => '東京',
        ]);

        $this->actingAsPerson($this->manager())->get('/assign')
            ->assertOk()
            ->assertSee('editNeed(', false)
            ->assertSee('/assign-publish/count', false)
            ->assertSee('✎ 人数', false);
    }

    /** 数字を送ると運営人数が変わる。 */
    public function test_count_can_be_changed(): void
    {
        $p = ProjectFactory::new()->create([
            'start_date' => now()->addDays(3)->format('Y-m-d'), 'required_count' => 8, 'office' => '東京',
        ]);

        $this->actingAsPerson($this->manager())
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => 12])
            ->assertOk()
            ->assertJsonPath('count', 12)
            ->assertJsonPath('needStaff', 12);

        $this->assertSame(12, (int) $p->fresh()->required_count);
    }

    /**
     * ⚠ 人が数字を入れたら「仮」の印は外れる
     * （CSV取込が空欄から置いた仮の数と、人が決めた数を見分けられなくなるため）。
     */
    public function test_saving_clears_the_provisional_flag(): void
    {
        $p = ProjectFactory::new()->create([
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'required_count' => 5, 'count_tentative' => true, 'office' => '東京',
        ]);

        $this->actingAsPerson($this->manager())
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => 9])
            ->assertOk();

        $fresh = $p->fresh();
        $this->assertSame(9, (int) $fresh->required_count);
        $this->assertFalse((bool) $fresh->count_tentative);
    }

    /** 空で送れば「未定」に戻る。スタッフ画面で見せる既定（5名）はサーバーが返す。 */
    public function test_empty_means_undecided_and_server_returns_the_default(): void
    {
        $p = ProjectFactory::new()->create([
            'start_date' => now()->addDays(3)->format('Y-m-d'), 'required_count' => 8, 'office' => '東京',
        ]);

        $this->actingAsPerson($this->manager())
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => null])
            ->assertOk()
            ->assertJsonPath('count', null)
            ->assertJsonPath('needStaff', 5);

        $this->assertNull($p->fresh()->required_count);
    }

    /** ⚠ 他拠点の案件は、URLを直に叩いても直せない（拠点の門はこの入口にも効く）。 */
    public function test_other_office_project_is_rejected(): void
    {
        $p = ProjectFactory::new()->create([
            'start_date' => now()->addDays(3)->format('Y-m-d'), 'required_count' => 8, 'office' => '大阪',
        ]);
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)
            ->postJson('/assign-publish/count', ['id' => $p->id, 'count' => 99])
            ->assertStatus(403);

        $this->assertSame(8, (int) $p->fresh()->required_count);
    }

    /** 存在しない案件は弾く。 */
    public function test_unknown_project_is_rejected(): void
    {
        $this->actingAsPerson($this->manager())
            ->postJson('/assign-publish/count', ['id' => 'P-9999', 'count' => 3])
            ->assertStatus(422);

        $this->assertSame(0, Project::where('id', 'P-9999')->count());
    }
}
