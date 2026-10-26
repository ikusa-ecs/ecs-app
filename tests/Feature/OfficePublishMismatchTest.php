<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\OfficePublish;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「公開している拠点」は残っているのに、公開のスイッチだけ切れている案件（2026-10-26 0483）。
 * 公開し直せること・一覧と食い違わないことを見張る。
 */
class OfficePublishMismatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_again_when_switch_off_but_office_list_remains(): void
    {
        $p = ProjectFactory::new()->create(['office' => '東京']);
        $p->forceFill(['staff_published' => false, 'published_offices' => ['東京']])->save();

        $this->assertFalse(OfficePublish::isPublishedFor($p, '東京'));
        $this->assertTrue(OfficePublish::set($p, '東京', true), '公開し直しは「変わった」と返すこと');
        $p->save();

        $this->assertTrue((bool) $p->fresh()->staff_published);
        $this->assertTrue(OfficePublish::isPublishedFor($p->fresh(), '東京'));
    }

    public function test_already_consistent_publish_is_a_no_op(): void
    {
        $p = ProjectFactory::new()->create(['office' => '東京']);
        $p->forceFill(['staff_published' => true, 'published_offices' => ['東京']])->save();

        $this->assertFalse(OfficePublish::set($p, '東京', true));
    }
}
