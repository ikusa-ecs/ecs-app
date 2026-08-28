<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 経験回数の画面（/experience）。2026-08-28 baba要望で名簿の詳細から独立させた。
 *
 * 【なぜ独立させたか】
 * 名簿の詳細だと1人ずつ開かないと見られず、
 * 「このコンテンツをやったことがある人は誰か」を探せなかった。
 * ⚠ 拠点で絞れることが要件（baba）。
 */
class ExperienceScreenTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $id, string $name, string $office, string $role = 'staff', bool $active = true): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'office' => $office,
            'role' => $role, 'active' => $active, 'must_onboard' => false,
        ]);
    }

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'role' => 'employee',
            'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 終わった案件に確定でアサインする（＝経験として数えられる形）。 */
    private function past(string $projectId, string $content, string $staffId, string $role = 'MC'): void
    {
        $day = Carbon::today()->copy()->subDays(30);
        if (! Project::find($projectId)) {
            Project::create([
                'id' => $projectId,
                'project_name' => $content,
                'content_names' => [$content],
                'start_date' => $day->toDateString(),
                'office' => '東京',
                'status' => '確定',
            ]);
        }
        Assignment::create([
            'project_id' => $projectId, 'staff_id' => $staffId,
            'date' => $day->toDateString(), 'role' => $role, 'status' => '確定',
        ]);
    }

    /** 画面が開けて、数えた結果が渡っている。 */
    public function test_screen_opens_with_counts(): void
    {
        $admin = $this->admin();
        $s = $this->person('S-101', '山田 太郎', '東京');
        $this->past('P-1', '水合戦', $s->id);

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('experience', fn ($e) => ($e['S-101']['projects'] ?? 0) === 1)
            // ⚠ Blade の @verbatim の切れ目を間違えるとスクリプトが途中から消える。
            //   画面は真っ白にならないので気づけない＝見張る。
            ->assertSee('function exRender', false)
            ->assertSee('function renderPick', false)
            ->assertSee('id="tblPeople"', false);
    }

    /** ⚠ 拠点で絞るための選択肢と、自分の拠点が渡っている（baba要件）。 */
    public function test_office_filter_is_available(): void
    {
        $admin = $this->admin();

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('offices', fn ($o) => in_array('東京', $o, true))
            ->assertViewHas('myOffice', '東京');
    }

    /** 拠点は人ごとに渡す（画面で絞れるように）。空の人は「東京」あつかい。 */
    public function test_each_person_carries_an_office(): void
    {
        $admin = $this->admin();
        $this->person('S-102', '名古屋の人', '名古屋');
        $this->person('S-103', '拠点未設定', '');

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('people', function ($people) {
                $byId = collect($people)->keyBy('id');

                return $byId['S-102']['office'] === '名古屋'
                    && $byId['S-103']['office'] === '東京';
            });
    }

    /** コンテンツの選択肢は「実際に誰かがやったもの」だけ（0件を選ばせない）。 */
    public function test_content_options_come_from_real_results(): void
    {
        $admin = $this->admin();
        $a = $this->person('S-104', 'Aさん', '東京');
        $b = $this->person('S-105', 'Bさん', '東京');
        $this->past('P-2', '水合戦', $a->id);
        $this->past('P-3', '水合戦', $b->id);
        $this->past('P-4', '防災訓練', $a->id);

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('contentOptions', function ($opts) {
                // 多い順＝水合戦(2) → 防災訓練(1)。
                return $opts === ['水合戦', '防災訓練'];
            });
    }

    /** ポジションの選択肢は決まった並び（D→SD→OP→MC→…）で返す。 */
    public function test_role_options_keep_the_fixed_order(): void
    {
        $admin = $this->admin();
        $a = $this->person('S-106', 'Cさん', '東京');
        $this->past('P-5', '水合戦', $a->id, 'MC');
        $this->past('P-6', '水合戦', $a->id, 'D');

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('roleOptions', function ($roles) {
                return array_column($roles, 'code') === ['D', 'MC'];
            });
    }

    /** CSVで落とせる（コンテンツ別）。 */
    public function test_csv_export_by_content(): void
    {
        $admin = $this->admin();
        $a = $this->person('S-107', '山田 太郎', '東京');
        $this->past('P-7', '水合戦', $a->id, 'MC');

        $res = $this->actingAsPerson($admin)->get('/experience/export.csv')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('山田太郎', $csv);   // スタッフは詰めて保存される
        $this->assertStringContainsString('水合戦', $csv);
        $this->assertStringContainsString('コンテンツ', $csv);
        // ⚠ Excelで開いたときに文字化けしないよう BOM を付けている。
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
    }

    /** CSVで落とせる（ポジション別）。 */
    public function test_csv_export_by_role(): void
    {
        $admin = $this->admin();
        $a = $this->person('S-108', '佐藤 花子', '東京');
        $this->past('P-8', '水合戦', $a->id, 'D');

        $csv = $this->actingAsPerson($admin)->get('/experience/export.csv?type=role')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('ポジション', $csv);
        $this->assertStringContainsString('D（ディレクター）', $csv);
    }

    /** 経験が1件も無い人はCSVに行を作らない（読みにくくなるため）。 */
    public function test_csv_skips_people_without_results(): void
    {
        $admin = $this->admin();
        $this->person('S-109', '実績なしさん', '東京');

        $csv = $this->actingAsPerson($admin)->get('/experience/export.csv')
            ->assertOk()->streamedContent();

        $this->assertStringNotContainsString('実績なしさん', $csv);
    }

    /** 退職・停止の人も渡す（過去に誰がやったかを追えるように）。画面側で出し分ける。 */
    public function test_inactive_people_are_still_included(): void
    {
        $admin = $this->admin();
        $this->person('S-110', '退職した人', '東京', 'staff', false);

        $this->actingAsPerson($admin)->get('/experience')
            ->assertOk()
            ->assertViewHas('people', function ($people) {
                $row = collect($people)->firstWhere('id', 'S-110');

                return $row && $row['active'] === false;
            });
    }

    /** 左メニューから行ける（作ったのに辿り着けない画面にしない）。 */
    public function test_sidebar_has_a_link(): void
    {
        $admin = $this->admin();

        $this->actingAsPerson($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('href="/experience"', false);
    }
}
