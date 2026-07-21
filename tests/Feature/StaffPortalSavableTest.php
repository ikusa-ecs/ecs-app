<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「保存できるスタッフ用テストログイン（B案）」の結合テスト。
 *
 * ねらい：スタッフ画面のテストで、
 *   ・保存あり(savable)のテスト垢 S-900 … 応募・稼働希望が実DBに“保存される”
 *   ・見本専用のテスト垢 TEST-STAFF     … これまでどおり“保存されない”（見本）
 * を確認する。判定は TestAccounts::isMockOnly（テスト垢かつ savable でない）で行う。
 */
class StaffPortalSavableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // テストログインの仕組み（TestAccounts）を有効にしておく。
        config(['ecs.test_login' => true]);
    }

    /** 保存あり(S-900)：エントリー（応募）が applications に保存される。 */
    public function test_savable_staff_persists_entry(): void
    {
        $p = ProjectFactory::new()->create();
        $me = PersonFactory::new()->staff()->create([
            'id' => 'S-900', 'email' => 'test-db-staff@example.com',
        ]);

        $this->actingAsPerson($me)
            ->postJson('/staff-portal/entry', [
                'project_id' => $p->id,
                'intent'     => '希望',
                'note'       => 'よろしくお願いします',
                'action'     => 'apply',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'saved' => true, 'applied' => true]);

        $this->assertDatabaseHas('applications', [
            'staff_id'   => 'S-900',
            'project_id' => $p->id,
            'intent'     => '希望',
        ]);
    }

    /** 保存あり(S-900)：稼働希望が shift_preferences に保存される。 */
    public function test_savable_staff_persists_availability(): void
    {
        $me = PersonFactory::new()->staff()->create([
            'id' => 'S-900', 'email' => 'test-db-staff@example.com',
        ]);

        $this->actingAsPerson($me)
            ->postJson('/staff-portal/availability', [
                'period' => '2026-08',
                'state'  => ['2026-8-10' => 'ok', '2026-8-11' => 'ng'],
                'memo'   => 'お盆前は入れます',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // date は日時型で保存される（YYYY-MM-DD 00:00:00）。ok=稼働可 / ng=NG が残る。
        $this->assertDatabaseHas('shift_preferences', [
            'staff_id'     => 'S-900',
            'date'         => '2026-08-10 00:00:00',
            'availability' => '稼働可',
        ]);
        $this->assertDatabaseHas('shift_preferences', [
            'staff_id'     => 'S-900',
            'date'         => '2026-08-11 00:00:00',
            'availability' => 'NG',
        ]);
    }

    /** 見本専用(TEST-STAFF)：エントリーは保存されない（saved:false）。 */
    public function test_mock_only_staff_does_not_persist_entry(): void
    {
        $p = ProjectFactory::new()->create();
        $me = PersonFactory::new()->staff()->create([
            'id' => 'TEST-STAFF', 'email' => 'test-staff@ecs.local',
        ]);

        $this->actingAsPerson($me)
            ->postJson('/staff-portal/entry', [
                'project_id' => $p->id,
                'intent'     => '希望',
                'action'     => 'apply',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'saved' => false]);

        $this->assertDatabaseMissing('applications', ['staff_id' => 'TEST-STAFF']);
    }
}
