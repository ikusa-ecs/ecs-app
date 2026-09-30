<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ画面の募集一覧：追加案件でも、募集が終わった（締切・満員）ものは先頭に出さず日付の位置へ（2026-09-30 baba要望）。
 * ⚠ 並べ方は画面のJS。ここでは並べ替えの条件が消えていないことを見張る。
 */
class StaffPortalExtraClosedOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_締切・満員の追加案件は先頭にしない(): void
    {
        $staff = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);

        $html = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->getContent();

        $this->assertStringContainsString("const ax = (pa.extra && pa.state !== 'closed') ? 1 : 0", $html);
        $this->assertStringContainsString("list.filter(j => j.extra && j.state !== 'closed').length", $html,
            '「追加募集が◯件」のお知らせに、募集が終わったものまで数えている');
    }
}
