<?php

namespace Tests\Feature;

use App\Models\ProjectFinance;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マイページ・収支入力（/mypage-finance）の保存の結合テスト。
 *
 * ねらい：「入力したのに保存されない」を防ぐ。
 *   ・社員が収支（売上・経費明細・メモ）を保存 → project_finances に実際に残る
 *   ・経費明細（JSON）が正しい形で保存される
 *   ・マイナス金額は0に補正・空の費目は保存されない（cleanItems の安全処理）
 *   ・同じ案件をもう一度保存すると上書きされる（案件1件＝1行／行が増えない）
 */
class FinanceSaveTest extends TestCase
{
    use RefreshDatabase;

    /** 社員：売上・経費明細・メモが project_finances に保存される。 */
    public function test_employee_persists_finance(): void
    {
        $p = ProjectFactory::new()->create();
        $me = PersonFactory::new()->create(); // 既定＝社員（tier:employee を通る）

        $this->actingAsPerson($me)
            ->postJson('/mypage-finance/save', [
                'project_id' => $p->id,
                'revenue'    => 500000,
                'items'      => [
                    'transport' => ['qty' => 3, 'amount' => 1200],
                    'meal'      => ['qty' => 1, 'amount' => 800],
                ],
                'memo'       => '交通費は実費精算',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('project_finances', [
            'project_id' => $p->id,
            'revenue'    => 500000,
            'memo'       => '交通費は実費精算',
        ]);

        // 経費明細（JSON）は array キャストで正しく復元される。
        $saved = ProjectFinance::where('project_id', $p->id)->first();
        $this->assertSame(3, $saved->items['transport']['qty']);
        $this->assertSame(1200, $saved->items['transport']['amount']);
        $this->assertSame(800, $saved->items['meal']['amount']);
    }

    /** cleanItems：マイナス金額は0に補正・空の費目（すべて0）は保存しない。 */
    public function test_finance_sanitizes_items(): void
    {
        $p = ProjectFactory::new()->create();
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)
            ->postJson('/mypage-finance/save', [
                'project_id' => $p->id,
                'revenue'    => 0,
                'items'      => [
                    'transport' => ['qty' => 2, 'amount' => -500], // 金額マイナス→0
                    'empty'     => ['qty' => 0, 'amount' => 0],     // すべて0→保存しない
                ],
            ])
            ->assertOk();

        $saved = ProjectFinance::where('project_id', $p->id)->first();
        // マイナスは0に補正され、その費目自体は数量>0で残る。
        $this->assertSame(0, $saved->items['transport']['amount']);
        $this->assertSame(2, $saved->items['transport']['qty']);
        // すべて0の費目は保存されない。
        $this->assertArrayNotHasKey('empty', $saved->items);
    }

    /** updateOrCreate：同じ案件を再保存すると上書き（行は1件のまま）。 */
    public function test_finance_overwrites_same_project(): void
    {
        $p = ProjectFactory::new()->create();
        $me = PersonFactory::new()->create();

        $this->actingAsPerson($me)->postJson('/mypage-finance/save', [
            'project_id' => $p->id, 'revenue' => 100000, 'memo' => '初回',
        ])->assertOk();

        $this->actingAsPerson($me)->postJson('/mypage-finance/save', [
            'project_id' => $p->id, 'revenue' => 250000, 'memo' => '修正後',
        ])->assertOk();

        // 行は1件だけ・中身は最後の値。
        $this->assertSame(1, ProjectFinance::where('project_id', $p->id)->count());
        $this->assertDatabaseHas('project_finances', [
            'project_id' => $p->id,
            'revenue'    => 250000,
            'memo'       => '修正後',
        ]);
    }
}
