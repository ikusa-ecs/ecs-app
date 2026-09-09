<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\ProjectHistory;
use App\Support\StaffProjectNews;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面の「最近の変更（あなたに関係するもの）」を守るテスト（2026-09-09 baba要望
 * 「社員側の編集履歴みたいなやつの、スタッフにかかわることだけがのっているバージョンがほしい」）。
 *
 * ⚠ ここで守りたいのは4つ。
 *   ① 自分が関わる案件の変更が出る（集合時間が変わった、など）。
 *   ② **社内の数字は出さない**（運営人数・確度など。スタッフに関係しない）。
 *   ③ **見せてよい案件だけ**（自分が関わる案件か、いま公開中の募集案件）。
 *   ④ 終わった案件の変更は出さない（もう関係ない）。
 */
class StaffProjectNewsTest extends TestCase
{
    use RefreshDatabase;

    private function staff()
    {
        return PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);
    }

    private function history($project, string $field, string $from, string $to, string $action = 'updated'): void
    {
        ProjectHistory::create([
            'project_id' => $project->id,
            'project_name' => $project->project_name,
            'action' => $action,
            'field' => $field,
            'field_label' => \App\Support\ProjectFieldLabels::label($field),
            'old_value' => $from,
            'new_value' => $to,
        ]);
    }

    /** 自分がアサインされている案件の「集合時間が変わった」が出る。 */
    public function test_it_shows_changes_on_my_project(): void
    {
        $me = $this->staff();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'project_name' => 'わたしの案件', 'office' => '東京',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'FC', 'status' => '確定',
        ]);
        $this->history($p, 'start_time', '9:00', '8:30');

        // ⚠ 案件を作った時点で「新しい募集が出ました」の記録も残る（案件登録の履歴）。
        //   ここで見たいのは「変更」の行なので、そこだけ取り出す。
        $news = collect(StaffProjectNews::forPerson($me))->where('action', 'updated')->values();

        $this->assertCount(1, $news);
        $this->assertSame('わたしの案件', $news[0]['name']);
        $this->assertSame('8:30', $news[0]['to']);
        $this->assertTrue($news[0]['mine'], '自分の案件だと分かるようにすること');
    }

    /** ⚠ 社内の数字（運営人数など）は出さない。 */
    public function test_it_hides_internal_fields(): void
    {
        $me = $this->staff();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'), 'office' => '東京',
        ]);
        Application::create(['project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望']);
        $this->history($p, 'required_count', '5', '8');
        $this->history($p, 'yomi', 'B', 'A');

        // ⚠ 「変更」の行が1つも無いこと（案件が出たことの記録だけは残ってよい）。
        $changes = collect(StaffProjectNews::forPerson($me))->where('action', 'updated');

        $this->assertCount(0, $changes, '社内の数字を出してしまっている');
    }

    /** ⚠ 公開していない・自分と関係ない案件の変更は出さない。 */
    public function test_it_hides_projects_the_staff_cannot_see(): void
    {
        $me = $this->staff();
        $secret = ProjectFactory::new()->create([   // published() なし＝未公開
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'project_name' => '社内だけの案件', 'office' => '東京',
        ]);
        $this->history($secret, 'start_time', '9:00', '8:00');

        $this->assertSame([], StaffProjectNews::forPerson($me), '公開していない案件を見せてしまっている');
    }

    /** 公開中の案件が新しく出たことは、関わっていなくても知らせる。 */
    public function test_new_published_projects_are_announced(): void
    {
        $me = $this->staff();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(7)->format('Y-m-d'),
            'project_name' => '新しい募集', 'office' => '東京',
        ]);
        // ⚠ 案件を登録した時点で「created」の記録が残る（App\Support\ProjectHistoryRecorder）。
        //   ここでは、それがそのままスタッフのお知らせになることを確かめる。

        $news = StaffProjectNews::forPerson($me);

        $this->assertCount(1, $news);
        $this->assertSame('created', $news[0]['action']);
        $this->assertFalse($news[0]['mine']);
    }

    /** ⚠ 終わった案件の変更は出さない。 */
    public function test_past_projects_are_not_shown(): void
    {
        $me = $this->staff();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->subDays(3)->format('Y-m-d'), 'office' => '東京',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'FC', 'status' => '確定',
        ]);
        $this->history($p, 'start_time', '9:00', '8:00');

        $this->assertSame([], StaffProjectNews::forPerson($me), '終わった案件の変更を出してしまっている');
    }

    /** スタッフ画面に出ていること。 */
    public function test_the_staff_screen_shows_the_news(): void
    {
        $me = $this->staff();
        $p = ProjectFactory::new()->published()->create([
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'project_name' => '時間が変わった案件', 'office' => '東京',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'FC', 'status' => '確定',
        ]);
        $this->history($p, 'start_time', '9:00', '8:30');

        $this->actingAsPerson($me)->get('/staff-portal')
            ->assertOk()
            ->assertSee('最近の変更')
            ->assertSee('時間が変わった案件')
            ->assertSee('が変わりました', false);
    }
}
