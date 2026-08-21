<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件一覧の「準備チェック」（2026-08-21 baba要望）。
 *
 * これまでは ✅／⬜ の表示だけで押せなかった。チェックボックスにして、押した時点で保存する。
 * 項目＝LINE作成／LINE概要送付／LINEダブチェ（新）／引き継ぎ／台本。
 * 保存の入口は他のセルと同じ POST /projects/cells で、送られたキーだけを更新する。
 */
class PrepCheckSaveTest extends TestCase
{
    use RefreshDatabase;

    /** チェックを1つ押すと、その1つだけが保存される。 */
    public function test_checking_one_item_saves_only_that_item(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create([
            'start_date'        => '2026-09-01',
            'prep_line_created' => false,
            'prep_handover'     => true,
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id'                => $project->id,
            'prep_line_created' => true,
        ])->assertOk();

        $project->refresh();

        $this->assertTrue((bool) $project->prep_line_created, '押した項目は保存される');
        $this->assertTrue((bool) $project->prep_handover, '送っていない項目はそのまま');
        $this->assertFalse((bool) $project->prep_line_double_check);
    }

    /** チェックを外すと false で保存される（送られたキーは false でも反映する）。 */
    public function test_unchecking_saves_false(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create([
            'start_date'             => '2026-09-02',
            'prep_line_double_check' => true,
        ]);

        $this->actingAsPerson($employee)->postJson('/projects/cells', [
            'id'                     => $project->id,
            'prep_line_double_check' => false,
        ])->assertOk();

        $this->assertFalse((bool) $project->fresh()->prep_line_double_check);
    }
}
