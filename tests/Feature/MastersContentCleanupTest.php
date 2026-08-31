<?php

namespace Tests\Feature;

use App\Models\Content;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マスタ管理のコンテンツまわり（2026-08-31 baba要望）。
 *
 * 見張るところ：
 *  1. 表の幅が足りていて「コンテンツ名」の欄が潰れない
 *     ⚠ 実際に起きた不具合＝略称の列（120px）を足したら固定幅の合計がパネルの幅を超え、
 *       残り全部（1fr）のコンテンツ名が幅ゼロになって **名前が消えたように見えた**。
 *       データは無事なので気づきにくい。ここは算数で見張れる。
 *  2. いらないコンテンツをまとめて削除できる（Administratorのみ）
 *  3. 案件で使われているコンテンツは削除しない
 *  4. 空白だけのコンテンツ名で台帳を増やさない
 */
class MastersContentCleanupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * コンテンツの表が、パネルの中に収まっている。
     *
     * パネルの中で使える幅 ＝ max-width −（padding 20px × 2）−（border 1px × 2）。
     * 必要な幅 ＝ 固定幅の列の合計 ＋ すき間（gap × 列数−1）＋ 名前の列の最小幅。
     */
    public function test_the_content_table_fits_inside_its_panel(): void
    {
        $blade = file_get_contents(resource_path('views/masters.blade.php'));

        // パネルの幅（.m-wrap.m-wide）
        $this->assertMatchesRegularExpression('/\.m-wrap\.m-wide\s*\{\s*max-width:\s*(\d+)px/', $blade);
        preg_match('/\.m-wrap\.m-wide\s*\{\s*max-width:\s*(\d+)px/', $blade, $m);
        $panel = (int) $m[1] - 20 * 2 - 1 * 2;

        // 列の指定（.m-row のうち .off でないもの＝コンテンツの表）
        preg_match('/\.m-row\s*\{[^}]*grid-template-columns:([^;]+);[^}]*gap:\s*(\d+)px/', $blade, $g);
        $this->assertNotEmpty($g, '.m-row の grid-template-columns が読めませんでした');
        $spec = trim($g[1]);
        $gap = (int) $g[2];

        // 「minmax(150px, 1fr)」は最小幅150pxとして数える。「1fr」だけの列は0pxになりうる。
        $cols = preg_split('/\s+(?![^(]*\))/', $spec);
        $need = 0;
        $hasFlexible = false;
        foreach ($cols as $col) {
            if (preg_match('/^minmax\(\s*(\d+)px/', $col, $mm)) {
                $need += (int) $mm[1];
                $hasFlexible = true;
            } elseif (preg_match('/^(\d+)px$/', $col, $mm)) {
                $need += (int) $mm[1];
            } elseif (str_contains($col, 'fr')) {
                $hasFlexible = true;   // 最小幅なし＝潰れうる
            }
        }
        $need += $gap * (count($cols) - 1);

        $this->assertTrue(
            $hasFlexible,
            'コンテンツ名の列に minmax(...px, 1fr) が要ります（最小幅が無いと幅ゼロまで潰れます）'
        );
        $this->assertLessThanOrEqual(
            $panel,
            $need,
            "コンテンツの表がパネルに収まっていません（必要 {$need}px ／ 使える {$panel}px）。".
            '列を足したら .m-wrap.m-wide の max-width も広げてください。'
        );
    }

    /** 使われていないコンテンツをまとめて削除できる（Administrator）。 */
    public function test_admin_can_delete_unused_contents_in_bulk(): void
    {
        $admin = PersonFactory::new()->admin()->create();
        Content::create(['id' => 'CT-901', 'content_name' => 'いらない1', 'active' => true]);
        Content::create(['id' => 'CT-902', 'content_name' => 'いらない2', 'active' => true]);
        Content::create(['id' => 'CT-903', 'content_name' => '残すもの', 'active' => true]);

        $this->actingAsPerson($admin)
            ->post('/masters/contents/bulk-delete', ['del' => ['CT-901', 'CT-902']])
            ->assertRedirect();

        $this->assertNull(Content::find('CT-901'));
        $this->assertNull(Content::find('CT-902'));
        $this->assertNotNull(Content::find('CT-903'));
    }

    /**
     * 案件で使われているコンテンツは削除しない。
     * ⚠ ここが緩いと、案件が指しているIDが行方不明になり、案件のコンテンツ名が出なくなる。
     */
    public function test_contents_used_by_a_project_are_not_deleted(): void
    {
        $admin = PersonFactory::new()->admin()->create();
        Content::create(['id' => 'CT-911', 'content_name' => '使用中', 'active' => true]);
        ProjectFactory::new()->create(['content_ids' => ['CT-911']]);

        $this->actingAsPerson($admin)
            ->post('/masters/contents/bulk-delete', ['del' => ['CT-911']])
            ->assertRedirect();

        $this->assertNotNull(Content::find('CT-911'), '使われているコンテンツが消えてしまいました');
    }

    /** 管理者（manager）は使えない＝削除は Administrator のみ。 */
    public function test_a_manager_cannot_use_bulk_delete(): void
    {
        $manager = PersonFactory::new()->manager()->create();
        Content::create(['id' => 'CT-921', 'content_name' => 'いらない', 'active' => true]);

        $this->actingAsPerson($manager)
            ->post('/masters/contents/bulk-delete', ['del' => ['CT-921']]);

        $this->assertNotNull(Content::find('CT-921'));
    }

    /** 一覧に「使われている件数」が渡っている（選べないようにするため）。 */
    public function test_the_master_screen_receives_usage_counts(): void
    {
        $admin = PersonFactory::new()->admin()->create();
        Content::create(['id' => 'CT-931', 'content_name' => '使用中', 'active' => true]);
        ProjectFactory::new()->create(['content_ids' => ['CT-931']]);
        ProjectFactory::new()->create(['content_ids' => ['CT-931']]);

        $res = $this->actingAsPerson($admin)->get('/masters')->assertOk();

        $this->assertSame(2, $res->viewData('usedCounts')['CT-931'] ?? 0);
        $this->assertTrue($res->viewData('canDelete'));
    }

    /**
     * 空白だけのコンテンツ名で台帳を増やさない。
     * ⚠ 増えると、マスタ管理に「名前の欄が空の行」が並んで見分けが付かなくなる。
     */
    public function test_a_blank_content_name_does_not_create_a_ledger_row(): void
    {
        $before = Content::count();
        $ids = (new \ReflectionMethod(\App\Http\Controllers\ProjectController::class, 'resolveContentIds'));
        $ids->setAccessible(true);
        $result = $ids->invoke(app(\App\Http\Controllers\ProjectController::class), collect(['   ', '']));

        $this->assertSame([], $result);
        $this->assertSame($before, Content::count());
    }
}
