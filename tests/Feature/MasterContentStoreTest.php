<?php

namespace Tests\Feature;

use App\Models\Content;
use Database\Factories\ContentFactory;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マスタ（コンテンツ）登録の結合テスト。テスト仕様書 IT-MST-01。
 *
 * 【重要な注意（本番MySQL）】
 * コンテンツIDの採番は MasterController::nextContentId() で
 *   orderByRaw('CAST(SUBSTR(id, 4) AS INTEGER) DESC')
 * を使っている。この `AS INTEGER` は SQLite では通るが **MySQL では構文エラー**（正しくは AS SIGNED）。
 * 本テストは開発用SQLiteで「採番が正しく連番になる」ことを保証するもので、
 * MySQL互換の確認は別途 MySQL 実行（テスト仕様書 ST-DB-01）で行う必要がある。
 */
class MasterContentStoreTest extends TestCase
{
    use RefreshDatabase;

    /** IT-MST-01：最初のコンテンツは CT-001 で採番される。 */
    public function test_first_content_is_numbered_ct_001(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/masters/contents', [
            'content_name' => '水合戦',
        ])->assertRedirect();

        $this->assertDatabaseHas('contents', [
            'id'           => 'CT-001',
            'content_name' => '水合戦',
        ]);
    }

    /** IT-MST-01：既存の最大番号の次で採番される（CT-005 があれば CT-006）。 */
    public function test_content_id_increments_from_existing_max(): void
    {
        $employee = PersonFactory::new()->create();
        ContentFactory::new()->create(['id' => 'CT-005', 'content_name' => '既存コンテンツ']);

        $this->actingAsPerson($employee)->post('/masters/contents', [
            'content_name' => '新コンテンツ',
        ])->assertRedirect();

        $this->assertDatabaseHas('contents', [
            'id'           => 'CT-006',
            'content_name' => '新コンテンツ',
        ]);
    }

    /** コンテンツ名は必須。未入力はバリデーションエラーで保存されない。 */
    public function test_content_name_is_required(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/masters/contents', [])
            ->assertSessionHasErrors('content_name');

        $this->assertSame(0, Content::count());
    }
}
