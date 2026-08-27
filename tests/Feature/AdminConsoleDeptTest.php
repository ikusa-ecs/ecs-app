<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 権限の変更（昇格・降格）の一覧に「所属」を出す（2026-08-27 baba要望）。
 *
 * なぜ＝誰を管理者に上げるか決めるとき、氏名と社員番号だけでは分かりにくい。
 * 兼務がある人は2つめ以降も出す（主な所属が先頭）。色の正本は App\Support\Departments。
 */
class AdminConsoleDeptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $extra = [])
    {
        return PersonFactory::new()->create(array_merge([
            'role' => 'employee',
            'permission' => 'admin',
            'office' => '東京',
            'must_onboard' => false,
        ], $extra));
    }

    /** 所属がバッジで出る。 */
    public function test_department_is_shown(): void
    {
        $me = $this->admin(['id' => 'E-001', 'name' => '田中 健一', 'department' => 'イベプラ']);

        $html = $this->actingAsPerson($me)->get('/admin-console')->assertOk()->getContent();

        $this->assertStringContainsString('所属', $html);
        $this->assertStringContainsString('イベプラ', $html);
    }

    /** 兼務がある人は2つめ以降も出る。 */
    public function test_second_department_is_shown(): void
    {
        $me = $this->admin([
            'id' => 'E-001', 'name' => '馬場 智之',
            'department' => 'イベプラ', 'departments' => ['イベプラ', 'セールス'],
        ]);

        $html = $this->actingAsPerson($me)->get('/admin-console')->assertOk()->getContent();

        $this->assertStringContainsString('イベプラ', $html);
        $this->assertStringContainsString('セールス', $html);
    }

    /** 所属が空の人は「未設定」と出る（空欄にすると入れ忘れか無所属か分からない）。 */
    public function test_missing_department_is_labelled(): void
    {
        $me = $this->admin(['id' => 'E-001', 'name' => '未設定 太郎', 'department' => null]);

        $html = $this->actingAsPerson($me)->get('/admin-console')->assertOk()->getContent();

        $this->assertStringContainsString('未設定', $html);
    }
}
