<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面が、どんなデータでも500にならないこと（2026-08-28 baba報告：
 * 500のエラー画面になったスタッフがいた）。
 *
 * ⚠ 全員ではなく特定の人だけ落ちる場合、原因は「その人のデータ」か「その人が見る案件」にある。
 *   ここで思いつく限りの欠けたデータを並べて、落ちる組み合わせを見つける／再発を止める。
 */
class StaffPortalSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $id, array $extra = []): Person
    {
        return PersonFactory::new()->create(array_merge([
            'id' => $id, 'name' => 'スタッフ'.$id, 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ], $extra));
    }

    private function project(string $id, array $extra = []): Project
    {
        return Project::create(array_merge([
            'id' => $id, 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => Carbon::today()->copy()->addDays(10)->toDateString(),
            'office' => '東京', 'status' => '調整中',
            'is_recruiting' => true, 'staff_published' => true,
        ], $extra));
    }

    /** 何も無いスタッフ（登録したて）。 */
    public function test_brand_new_staff(): void
    {
        $this->actingAsPerson($this->staff('S-901'))->get('/staff-portal')->assertOk();
    }

    /** 臨時スタッフ（メールも入社日も無い・その場で登録した人）。 */
    public function test_spot_staff_without_email(): void
    {
        $me = $this->staff('S-902', [
            'email' => null, 'hire_date' => null, 'is_spot' => true, 'password' => null,
        ]);
        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** ⚠ 開催日が入っていない案件にアサインされている人。 */
    public function test_assignment_on_a_project_without_a_date(): void
    {
        $me = $this->staff('S-903');
        $p = $this->project('P-NODATE', ['start_date' => null]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => Carbon::today()->copy()->addDays(3)->toDateString(),
            'role' => 'FC', 'status' => '確定',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** ⚠ 案件が消えているのにアサインだけ残っている（取込の途中で消したとき等）。 */
    public function test_assignment_whose_project_is_gone(): void
    {
        $me = $this->staff('S-904');
        Assignment::create([
            'project_id' => 'P-GONE', 'staff_id' => $me->id,
            'date' => Carbon::today()->copy()->addDays(3)->toDateString(),
            'role' => 'FC', 'status' => '確定',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** ⚠ 案件が消えているのにエントリーだけ残っている。 */
    public function test_application_whose_project_is_gone(): void
    {
        $me = $this->staff('S-905');
        Application::create([
            'project_id' => 'P-GONE2', 'staff_id' => $me->id, 'intent' => '希望', 'note' => 'よろしく',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** 役割が空・見たことのない役割コードのアサイン。 */
    public function test_assignment_with_unknown_role(): void
    {
        $me = $this->staff('S-906');
        $p = $this->project('P-ROLE');
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $p->start_date->toDateString(), 'role' => 'ZZZ', 'status' => '確定',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** 実施形態・時間・人数がぜんぶ空の案件が募集に出ている。 */
    public function test_project_with_everything_empty(): void
    {
        $me = $this->staff('S-907');
        $this->project('P-EMPTY', [
            'format' => null, 'client' => null, 'location' => null,
            'start_time' => null, 'end_time' => null, 'required_count' => null,
            'project_name' => '', 'content_names' => [],
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** 拠点が空のスタッフ（名簿の拠点が未設定）。 */
    public function test_staff_without_office(): void
    {
        $me = $this->staff('S-908', ['office' => null]);
        $this->project('P-OFC');

        $this->actingAsPerson($me)->get('/staff-portal')->assertOk();
    }

    /** 月を切り替えたとき（?period=）。おかしな値でも落ちない。 */
    public function test_odd_period_parameter(): void
    {
        $me = $this->staff('S-909');

        $this->actingAsPerson($me)->get('/staff-portal?period=2026-13')->assertOk();
        $this->actingAsPerson($me)->get('/staff-portal?period=あいうえお')->assertOk();
    }

    /** スタッフが開ける他の画面も落ちない。 */
    public function test_other_staff_screens(): void
    {
        $me = $this->staff('S-910');

        foreach (['/guide-staff', '/profile', '/password'] as $url) {
            $this->actingAsPerson($me)->get($url)->assertOk();
        }
    }
}
