<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\ProjectFieldLabels;
use App\Support\RecruitStatus;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 運営人数を「全体人数」と「IKUSA」の2つで持つ（2026-09-18 baba要望）。
 *
 * 【決めたこと（2026-09-18 baba）】
 *   ・**全体人数＝これまでの運営人数**（projects.required_count）。
 *     募集・残り◯名・自動アサイン・スタッフ画面の締切は**今までどおりこちらで動く**。
 *   ・**IKUSA＝そのうち自社で出す人数**（projects.ikusa_count）。覚えておくための数字で、計算には使わない。
 *   ・2つとも必須。逃げ道は「人数は仮（未定）」ひとつ（チェック1つで両方を仮にする）。
 *
 * ⚠ ここで守りたいのは「IKUSAの人数を入れても、スタッフ側の募集が変わらない」こと。
 *   計算の入口を付け替えると、スタッフの募集締切が静かに変わってしまう。
 */
class IkusaCountTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 2つとも保存される。 */
    public function test_both_counts_are_saved(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-10-01',
            'required_count' => '16',
            'ikusa_count'    => '10',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertSame(16, $p->required_count, '全体人数');
        $this->assertSame(10, $p->ikusa_count, 'IKUSAの人数');
    }

    /** IKUSAも「4〜6」のように幅で入れられる（全体人数と同じ読み方）。 */
    public function test_ikusa_count_accepts_a_range(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '謎解き',
            'start_date'     => '2026-10-01',
            'required_count' => '16',
            'ikusa_count'    => '4〜6',
            'intent'         => 'publish',
        ])->assertRedirect('/projects');

        $p = Project::firstOrFail();
        $this->assertSame(6, $p->ikusa_count, '多いほう');
        $this->assertSame(4, $p->ikusa_count_min, '少ないほう');
    }

    /** 確定で保存するときは必須（2026-09-18 baba「マストで」）。 */
    public function test_publish_requires_the_ikusa_count(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'  => '水合戦',
            'start_date'     => '2026-10-01',
            'required_count' => '16',
            'intent'         => 'publish',
        ])->assertSessionHasErrors(['ikusa_count']);

        $this->assertSame(0, Project::count(), '不備があれば登録しない');
    }

    /** 「人数は仮（未定）」にチェックが入っていれば、2つとも空でも通る。 */
    public function test_tentative_check_covers_both(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names'   => '水合戦',
            'start_date'      => '2026-10-01',
            'count_tentative' => '1',
            'intent'          => 'publish',
        ])->assertSessionHasNoErrors();

        $p = Project::firstOrFail();
        $this->assertNull($p->required_count);
        $this->assertNull($p->ikusa_count);
    }

    /** 下書きなら今までどおり空でも通る（あとで埋める運用）。 */
    public function test_draft_allows_blank(): void
    {
        $this->actingAsPerson($this->manager())->post('/project-form', [
            'content_names' => '水合戦',
            'intent'        => 'draft',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Project::firstOrFail()->ikusa_count);
    }

    /**
     * ⚠ いちばん大事なところ。
     * IKUSAの人数を入れても、**募集に使う必要人数は全体人数のまま**。
     */
    public function test_recruiting_still_uses_the_total_count(): void
    {
        $p = ProjectFactory::new()->create([
            'required_count' => 16,
            'ikusa_count' => 10,
        ]);

        $this->assertSame(16, RecruitStatus::need($p->required_count), '募集の必要人数は全体人数');
    }

    /** 編集画面を開いたとき、入れたとおりの形で戻る。 */
    public function test_edit_form_gets_the_ikusa_count_back(): void
    {
        $p = ProjectFactory::new()->create(['ikusa_count' => 6, 'ikusa_count_min' => 4]);

        $data = $this->actingAsPerson($this->manager())
            ->get('/project-form?project='.$p->id)->assertOk()->original->getData();

        $this->assertSame('4〜6', $data['editProject']['ikusa_count']);
    }

    /**
     * ⚠ 案件に列を足したら、編集履歴の日本語名にも足す（載っていない列は履歴に残らない）。
     */
    public function test_history_knows_the_new_columns(): void
    {
        $this->assertSame('運営人数（IKUSA）', ProjectFieldLabels::LABELS['ikusa_count'] ?? null);
        $this->assertArrayHasKey('ikusa_count_min', ProjectFieldLabels::LABELS);
    }

    /** 画面に2つの欄があること（名前が変わると保存されなくなる）。 */
    public function test_the_form_has_both_inputs(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/project-form')->assertOk()->getContent();

        $this->assertStringContainsString('name="required_count"', $html);
        $this->assertStringContainsString('name="ikusa_count"', $html);
        // 仮（未定）のチェックは1つで2つの欄をまとめて休みにする。
        $this->assertStringContainsString('data-tbd-for="requiredCount,ikusaCount"', $html);
    }
}
