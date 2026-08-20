<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件登録フォームの「確定で保存」の必須チェック（サーバー側）。
 *
 * これまでサーバー側は開催日の形式だけ見ており、画面のJSを通らない経路だと
 * 案件名も人数も空で登録できた（CSV一括取込は同じ3項目をサーバーで見ていた）。
 * 「未定」にチェックが入っているときは、これまでどおり空でも通す。
 */
class ProjectFormRequiredTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    /** 確定で保存：案件名・開催日・人数が空なら止める。 */
    public function test_publish_requires_name_date_and_count(): void
    {
        $before = Project::count();

        $this->actingAsPerson($this->emp())
            ->post('/project-form', ['intent' => 'publish'])
            ->assertSessionHasErrors(['content_names', 'start_date', 'required_count']);

        $this->assertSame($before, Project::count(), '不備があれば登録しない');
    }

    /** 「未定」にチェックが入っていれば、空でも確定で保存できる。 */
    public function test_publish_passes_with_tbd_checks(): void
    {
        $this->actingAsPerson($this->emp())
            ->post('/project-form', [
                'intent'          => 'publish',
                'content_tbd'     => '1',
                'date_tbd'        => '1',
                'count_tentative' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Project::count());
    }

    /** 下書き保存は、これまでどおり空でも通る（あとで埋める運用）。 */
    public function test_draft_allows_blank(): void
    {
        $this->actingAsPerson($this->emp())
            ->post('/project-form', ['intent' => 'draft'])
            ->assertSessionHasNoErrors();

        $this->assertSame('下書き', Project::first()->status);
    }

    /** ふつうに埋めてあれば通る。 */
    public function test_publish_passes_when_filled(): void
    {
        $this->actingAsPerson($this->emp())
            ->post('/project-form', [
                'intent'         => 'publish',
                'content_names'  => '水合戦',
                'start_date'     => Carbon::today()->addDays(10)->format('Y-m-d'),
                'required_count' => '12',
            ])
            ->assertSessionHasNoErrors();

        $p = Project::first();
        $this->assertSame(12, $p->required_count);
        $this->assertFalse((bool) $p->staff_published, '登録直後は必ず非公開');
    }

    /**
     * 危険日の判定に使う「日ごとの負荷」がDBの実案件から作られる
     * （以前は凍結モックの cases.js を見ていた）。編集中の案件は自分を数えない。
     */
    public function test_day_load_comes_from_database(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $a = ProjectFactory::new()->create([
            'start_date' => $date, 'scale' => '大型', 'format' => 'リアル', 'required_count' => 20,
        ]);
        $b = ProjectFactory::new()->create([
            'start_date' => $date, 'scale' => '小型', 'format' => 'オンライン', 'required_count' => 3,
        ]);
        ProjectFactory::new()->draft()->create(['start_date' => $date, 'required_count' => 50]);

        $emp = $this->emp();

        $load = $this->actingAsPerson($emp)->get('/project-form')
            ->assertOk()->original->getData()['dayLoad'];
        $this->assertCount(2, $load[$date], '下書きは数えない');
        $this->assertSame('online', collect($load[$date])->firstWhere('need', 3)['fmt']);

        // 編集で開いたときは、その案件自身を外す
        $load2 = $this->actingAsPerson($emp)->get('/project-form?project=' . $a->id)
            ->assertOk()->original->getData()['dayLoad'];
        $this->assertCount(1, $load2[$date]);
        $this->assertSame($b->project_name, $load2[$date][0]['name']);
    }
}
