<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 名簿から拠点（事務所）を直せるようにした（2026-08-27 baba要望）。
 *
 * なぜ要るか＝これまで画面から直す手段が無く、CSV取込か本人の入力しか無かった。
 * ⚠ people.office が空の人は自動で「東京」扱いになるため、間違ったままだと
 *   拠点で絞った瞬間に別の拠点のデータが見える。
 *
 * 直せるのは**管理者以上**（baba選択）。氏名・所属（Administratorのみ）とは別の線引き。
 */
class OfficeEditTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $permission, array $extra = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'role' => 'employee',
            'permission' => $permission,
            'office' => '東京',
            'must_onboard' => false,
        ], $extra));
    }

    /** 管理者はスタッフの拠点を直せる。 */
    public function test_manager_can_change_staff_office(): void
    {
        $me = $this->person('manager');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-201', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/staff/'.$staff->id.'/edit', ['office' => '名古屋'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('名古屋', $staff->fresh()->office);
    }

    /** 一般社員は直せない（拠点を間違えると別の拠点のデータが見えるため）。 */
    public function test_employee_cannot_change_staff_office(): void
    {
        $me = $this->person('employee');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-202', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/staff/'.$staff->id.'/edit', ['office' => '名古屋'])
            ->assertStatus(403);

        $this->assertSame('東京', $staff->fresh()->office);
    }

    /**
     * 拠点マスタに無い名前は受け付けない。
     * ⚠ タイポを通すと「どの拠点の一覧にも出てこない人」になる。
     */
    public function test_unknown_office_is_rejected(): void
    {
        $me = $this->person('manager');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-203', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/staff/'.$staff->id.'/edit', ['office' => '存在しない拠点'])
            ->assertStatus(422);

        $this->assertSame('東京', $staff->fresh()->office);
    }

    /** 空にすると「未設定」に戻せる（自動で東京として扱われる）。 */
    public function test_office_can_be_cleared(): void
    {
        $me = $this->person('manager');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-204', 'office' => '名古屋']);

        $this->actingAsPerson($me)
            ->postJson('/staff/'.$staff->id.'/edit', ['office' => ''])
            ->assertOk();

        $this->assertNull($staff->fresh()->office);
    }

    /** 社員の拠点も同じように直せる。 */
    public function test_manager_can_change_employee_office(): void
    {
        $me = $this->person('manager');
        $emp = $this->person('employee', ['id' => 'E-201', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/employees/'.$emp->id.'/profile', ['office' => '名古屋'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('名古屋', $emp->fresh()->office);
    }

    /** 画面に拠点の欄が出る（管理者以上）。 */
    public function test_screens_show_the_office_editor(): void
    {
        $me = $this->person('manager');
        PersonFactory::new()->staff()->create(['id' => 'S-205', 'office' => '東京']);

        $staffHtml = $this->actingAsPerson($me)->get('/staff')->assertOk()->getContent();
        $this->assertStringContainsString('ECS_CAN_MANAGE_OFFICE', $staffHtml);
        $this->assertStringContainsString('officeEditor', $staffHtml);

        $empHtml = $this->actingAsPerson($me)->get('/employees')->assertOk()->getContent();
        $this->assertStringContainsString('officeEditorHtml', $empHtml);
        $this->assertStringContainsString('saveOffice(', $empHtml);
    }
}
