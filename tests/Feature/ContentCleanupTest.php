<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Project;
use App\Support\ContentCleanup;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 取込で増えてしまったコンテンツの片づけ（2026-09-29 baba「コンテンツは量産しないでほしい」）。
 * ⚠ 案件は消さない。コンテンツのつながりをつなぎ直してから、いらないコンテンツだけ消す。
 */
class ContentCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function seedLedger(): void
    {
        Content::create(['id' => 'CT-001', 'content_name' => '謎パ', 'active' => true]);
        Content::create(['id' => 'CT-002', 'content_name' => '格付けバトル', 'active' => true]);
        Content::create(['id' => 'CT-003', 'content_name' => '謎パ・格付けバトル', 'active' => true]);   // まとめ
        Content::create(['id' => 'CT-004', 'content_name' => '謎　パ', 'active' => true]);              // 同じ（書き方違い）
        Content::create(['id' => 'CT-005', 'content_name' => 'ヒラメキ・クエスト', 'active' => true]);   // 1つの名前（候補にしない）
    }

    public function test_まとめと同じを見つける(): void
    {
        $this->seedLedger();

        $c = collect(ContentCleanup::candidates())->keyBy('id');

        $this->assertSame('まとめ', $c['CT-003']['kind']);
        $this->assertSame(['CT-001', 'CT-002'], array_column($c['CT-003']['targets'], 'id'));
        $this->assertSame('同じ', $c['CT-004']['kind']);
        $this->assertSame(['CT-001'], array_column($c['CT-004']['targets'], 'id'));
        $this->assertFalse($c->has('CT-005'), '台帳に分けた先が無い名前は候補にしない');
        $this->assertFalse($c->has('CT-001'));
    }

    public function test_案件をつなぎ直してから消す(): void
    {
        $this->seedLedger();
        $p = Project::create([
            'id' => 'P-2026-0001', 'project_name' => '謎パ・格付けバトル', 'content_ids' => ['CT-003'],
            'content_names' => ['謎パ・格付けバトル'], 'start_date' => '2026-10-01', 'office' => '東京', 'status' => '未着手',
        ]);

        $r = ContentCleanup::apply(['CT-003']);

        $this->assertSame(1, $r['projects']);
        $p->refresh();
        $this->assertSame(['CT-001', 'CT-002'], $p->content_ids);
        $this->assertSame(['謎パ', '格付けバトル'], $p->content_names);
        $this->assertNull(Content::find('CT-003'));
        $this->assertNotNull(Content::find('CT-004'), '選んでいないものは消さない');
    }

    public function test_Administratorだけが開ける(): void
    {
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($manager)->get('/masters/content-cleanup')->assertForbidden();
        $this->actingAsPerson($admin)->get('/masters/content-cleanup')->assertOk();
    }
}
