<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「自分の情報は自分で・他人の情報は管理者以上」（2026-09-01 baba決定）。
 *
 * 【なぜ】それまで**逆**になっていた。
 * ⚠ 入社年月日は初回の初期設定でしか入れられず、**間違えても本人が直せなかった**
 *   （実際に「入社年月日のつもりで生年月日を入れてしまった」方が出た）。
 * ⚠ いっぽう名簿からの編集（他人の所属・拠点・サイズ・入社年月日）は
 *   **一般社員でも直せた**（権限の制限が付いていなかった）。
 *
 * ⚠ できるポジション・NGペア・人柄メモは**業務のメモ**なので、今までどおり社員以上が直せる
 *   （アサイン担当が一般社員のことがあり、ここを絞ると仕事が止まる）。
 */
class PersonSelfEditTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $id, string $perm = 'employee')
    {
        return PersonFactory::new()->create([
            'id' => $id, 'role' => 'employee', 'permission' => $perm,
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 本人がマイプロフィールで入社年月日を直せる（間違えた人が自分で直せる）。 */
    public function test_a_person_can_fix_their_own_hire_date(): void
    {
        $me = $this->emp('E-SELF');
        $me->update(['hire_date' => '1990-04-15']);   // 生年月日を入れてしまった状態

        $this->actingAsPerson($me)->post('/profile', [
            'name' => $me->name,
            'hire_date' => '2024-04-01',
        ])->assertRedirect();

        $this->assertSame('2024-04-01', $me->refresh()->hire_date?->format('Y-m-d'));
    }

    /** 入社年月日の欄がマイプロフィールに出ている（保存が通っても欄が無ければ誰も直せない）。 */
    public function test_the_profile_page_shows_the_hire_date_field(): void
    {
        $me = $this->emp('E-SELF');

        $this->actingAsPerson($me)->get('/profile')
            ->assertOk()
            ->assertSee('name="hire_date"', false)
            ->assertSee('入社年月日');
    }

    /** マイページからも本人の情報を直せる（2026-09-01 baba要望）。 */
    public function test_a_person_can_edit_their_own_info_from_the_mypage(): void
    {
        $me = $this->emp('E-SELF');

        $this->actingAsPerson($me)->get('/mypage')
            ->assertOk()
            ->assertSee('氏名・所属・身長など')
            ->assertSee('action="/profile/basic"', false)
            ->assertSee('name="hire_date"', false);

        $this->actingAsPerson($me)->post('/profile/basic', [
            'name' => $me->name,
            'hire_date' => '2023-10-01',
            'height' => '172',
        ])->assertRedirect();

        $me->refresh();
        $this->assertSame('2023-10-01', $me->hire_date?->format('Y-m-d'));
        $this->assertSame('172', $me->height);
    }

    /**
     * ⚠ マイページの欄は、そこに出していない項目を消してはいけない。
     *   （/profile へ送ると、スタッフの一言アピール等まで空で上書きされる）
     */
    public function test_the_mypage_form_does_not_wipe_other_fields(): void
    {
        $me = $this->emp('E-SELF');
        $me->update(['profile_note' => '土日は空いています', 'name_kana' => 'しけん たろう']);

        $this->actingAsPerson($me)->post('/profile/basic', ['hire_date' => '2023-10-01']);

        $me->refresh();
        $this->assertSame('土日は空いています', $me->profile_note);
        $this->assertSame('しけん たろう', $me->name_kana);
    }

    /** スタッフも自分で「働き始めた年月」を直せる（社員と同じ・2026-09-01 baba要望）。 */
    public function test_a_staff_member_can_fix_their_own_hire_date(): void
    {
        $staff = PersonFactory::new()->staff()->create([
            'id' => 'S-800', 'office' => '東京', 'must_onboard' => false,
            'hire_date' => '1995-06-20',   // 生年月日を入れてしまった状態
        ]);

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('IKUSAで働き始めた年月');

        $this->actingAsPerson($staff)
            ->postJson('/staff-portal/profile', ['hire_date' => '2025-04-01'])
            ->assertOk();

        $this->assertSame('2025-04-01', $staff->refresh()->hire_date?->format('Y-m-d'));
    }

    /** ⚠ 一般社員は、他人の個人情報を直せない。 */
    public function test_an_employee_cannot_edit_someone_elses_profile(): void
    {
        $me = $this->emp('E-A');
        $other = $this->emp('E-B');

        $this->actingAsPerson($me)
            ->postJson('/employees/E-B/profile', ['hire_date' => '2020-01-01', 'height' => '180'])
            ->assertStatus(403);

        $this->assertNull($other->refresh()->hire_date, '他人の入社年月日が書き換えられています。');
    }

    /** 自分の行なら、名簿の画面からでも直せる。 */
    public function test_an_employee_can_edit_their_own_row(): void
    {
        $me = $this->emp('E-A');

        $this->actingAsPerson($me)
            ->postJson('/employees/E-A/profile', ['hire_date' => '2020-01-01'])
            ->assertOk();

        $this->assertSame('2020-01-01', $me->refresh()->hire_date?->format('Y-m-d'));
    }

    /** 管理者は他人の個人情報を直せる。 */
    public function test_a_manager_can_edit_someone_elses_profile(): void
    {
        $me = $this->emp('E-M', 'manager');
        $other = $this->emp('E-B');

        $this->actingAsPerson($me)
            ->postJson('/employees/E-B/profile', ['hire_date' => '2020-01-01'])
            ->assertOk();

        $this->assertSame('2020-01-01', $other->refresh()->hire_date?->format('Y-m-d'));
    }

    /**
     * ⚠ 他人の拠点は管理者以上だけ（2026-08-27 からの決まり。拠点は案件の見え方に効く）。
     *   ⚠ ここは**自分のぶんでも**管理者以上のまま（自分の拠点はマイプロフィールから直す）。
     */
    public function test_an_employee_cannot_change_someone_elses_office(): void
    {
        $me = $this->emp('E-A');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-900', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/staff/S-900/edit', ['office' => '福岡'])
            ->assertStatus(403);

        $this->assertSame('東京', $staff->refresh()->office);
    }

    /**
     * ⚠ できるポジション・NGペア・人柄メモは今までどおり社員以上が直せる。
     *   ここを絞ると、一般社員のアサイン担当の仕事が止まる（2026-09-01 baba選択）。
     */
    public function test_an_employee_can_still_edit_the_work_notes(): void
    {
        $me = $this->emp('E-A');
        $staff = PersonFactory::new()->staff()->create(['id' => 'S-901', 'office' => '東京']);

        $this->actingAsPerson($me)
            ->postJson('/staff/S-901/edit', ['impression' => '明るくて頼れる', 'ng' => '田中さんと不可'])
            ->assertOk();

        $this->assertSame('明るくて頼れる', $staff->refresh()->planner_impression);
    }
}
