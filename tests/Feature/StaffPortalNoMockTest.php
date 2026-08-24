<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面（/staff-portal）に見本（デモ）の案件を出さない（2026-08-24 baba指摘）。
 *
 * ⚠ 何が起きていたか：
 *   「DBに案件が1件も無ければ見本 /ecs/data/cases.js を出す」作りだったため、
 *   本番で案件を登録する前は、架空の案件19件が**スタッフ全員に見えていた**。
 *   スタッフへアカウントを配り始めるので、見本に戻る道をなくした。
 *
 * この画面が凍結モックから使っていたのは日付計算の関数（ECS_caseDate）だけなので、
 * その関数だけ画面の中で定義するようにした。
 */
class StaffPortalNoMockTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-001', 'name' => 'テスト太郎', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 凍結モックの案件ファイルを読み込んでいない。 */
    public function test_does_not_load_frozen_cases_mock(): void
    {
        $html = $this->actingAsPerson($this->staff())
            ->get('/staff-portal')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script src="/ecs/data/cases.js"', $html);
    }

    /** 案件が0件でも、見本の案件を出さない（0件のまま）。 */
    public function test_no_demo_jobs_when_no_projects(): void
    {
        $html = $this->actingAsPerson($this->staff())
            ->get('/staff-portal')->assertOk()->getContent();

        // 募集案件は空の配列で渡る。
        $this->assertStringContainsString('window.ECS_RECRUIT_JOBS = []', $html);
        // 見本に戻すための旗も、見本の配列も残っていない。
        $this->assertStringNotContainsString('ECS_USINGDB', $html);
        // 見本の配列そのもの（const _jobsSample = [）が無いこと。
        // ※ 撤去した経緯を書いたコメントに語が出るので、定義の形で確かめる。
        $this->assertStringNotContainsString('const _jobsSample', $html);
        // 見本データにあった架空のクライアント名が出ていない。
        $this->assertStringNotContainsString('◆◆株式会社', $html);
    }

    /** 日付計算の関数は画面の中で定義されている（凍結モックに頼らない）。 */
    public function test_case_date_helper_is_defined_locally(): void
    {
        $html = $this->actingAsPerson($this->staff())
            ->get('/staff-portal')->assertOk()->getContent();

        $this->assertStringContainsString('window.ECS_caseDate = function', $html);
    }

    /** 公開された募集案件はちゃんと出る（本物は出る）。 */
    public function test_published_recruiting_project_is_shown(): void
    {
        $me = $this->staff();
        ProjectFactory::new()->create([
            'id' => 'P-REAL', 'project_name' => '本物の水合戦', 'client' => '本物商事',
            'office' => '東京', 'start_date' => Carbon::today()->addDays(10)->format('Y-m-d'),
            'status' => '未着手', 'staff_published' => true, 'is_recruiting' => true,
            'required_count' => 5,
        ]);

        $html = $this->actingAsPerson($me)->get('/staff-portal')->assertOk()->getContent();

        $this->assertStringContainsString('P-REAL', $html);
        $this->assertStringNotContainsString('window.ECS_RECRUIT_JOBS = []', $html);
    }

    /** 募集が0件のときは、お知らせ文が「募集が出ています」にならない。 */
    public function test_notice_does_not_claim_openings_when_none(): void
    {
        $html = $this->actingAsPerson($this->staff())
            ->get('/staff-portal')->assertOk()->getContent();

        $this->assertStringContainsString('いまは募集中の案件はありません', $html);
        $this->assertStringNotContainsString('募集が出ています', $html);
    }
}
