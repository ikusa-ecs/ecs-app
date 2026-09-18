<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日程種別「前日設営」と「前泊の集合時間」（FBシート No.13・No.14／2026-09-10 桑江さん）。
 *
 * 13＝本番の前の日に会場を作りに行く日を、案件の種別として選べるようにした。
 *    あわせて「紐づく本番案件」のプルダウンに企業名を出す
 *    （案件名にコンテンツ名が入っていることが多く、名前と日付だけでは見分けられなかった）。
 * 14＝前泊ありの案件で「前の日に集まる時間」を持てるようにした。
 *    ⚠ 当日の集合時間（start_time）とは別の列にしてある。
 */
class SetupDayAndStayPreTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '登録する人', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 案件登録に必要な最低限の入力。 */
    private function form(array $over = []): array
    {
        return array_merge([
            'client' => 'テスト株式会社',
            'content_names' => '謎解き',
            'required_count' => '10',
            'start_date' => now()->addDays(20)->format('Y-m-d'),
            'office' => '東京',
            'ikusa_count'   => '5',
            'intent' => 'publish',
        ], $over);
    }

    public function test_前日設営として登録できる(): void
    {
        $me = $this->manager();
        $parent = ProjectFactory::new()->create([
            'id' => 'P-PARENT', 'office' => '東京', 'date_type' => '本番',
        ]);

        $this->actingAsPerson($me)->post('/project-form', $this->form([
            'has_sub' => '1',
            'date_type_sub' => '前日設営',
            'parent_project_id' => $parent->id,
        ]))->assertRedirect('/projects');

        $p = Project::where('date_type', '前日設営')->first();
        $this->assertNotNull($p, '前日設営として保存されていない');
        $this->assertSame('P-PARENT', $p->parent_project_id);
    }

    /** ⚠ 知らない種別はそのまま入れない（どの画面にも出てこない案件ができるため）。 */
    public function test_知らない種別は本番にする(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->form([
            'has_sub' => '1',
            'date_type_sub' => 'でたらめな種別',
        ]))->assertRedirect('/projects');

        $this->assertSame('本番', Project::first()->date_type);
    }

    public function test_紐づく本番案件に企業名が出る(): void
    {
        $me = $this->manager();
        ProjectFactory::new()->create([
            'id' => 'P-CLIENT', 'office' => '東京', 'date_type' => '本番',
            'project_name' => '謎解き', 'client' => 'サンプル商事',
            'start_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $this->actingAsPerson($me)->get('/project-form')
            ->assertOk()
            ->assertViewHas('parentProjects', function ($list) {
                $row = collect($list)->firstWhere('id', 'P-CLIENT');

                return $row && str_contains($row['label'], 'サンプル商事');
            });
    }

    public function test_前泊の集合時間を保存して本人に見せる(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->form([
            'lodging' => '前泊有',
            'stay_pre_meet_time' => '前日 18:00',
        ]))->assertRedirect('/projects');

        $p = Project::first();
        $this->assertSame('前日 18:00', $p->stay_pre_meet_time);
        // ⚠ 当日の集合時間に紛れ込んでいないこと（取り違えると全員が1日間違える）。
        $this->assertNotSame('前日 18:00', $p->start_time);
    }

    public function test_前泊の集合時間はスタッフ画面にも渡る(): void
    {
        $blade = file_get_contents(resource_path('views/staff_portal.blade.php'));

        // 確定アサインの詳細に出していること（出さないと結局チャットで伝えることになる）。
        $this->assertStringContainsString("add('前泊の集合時間', j.stayPreMeet);", $blade);
    }
}
