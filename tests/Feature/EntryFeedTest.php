<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * エントリー新着（来た順）。2026-08-21 baba要望。
 *
 * 「エントリー一覧」は案件ごとなので「いつ・誰から来たか」が追えなかった。
 * 来た順（新しい順）に並べ、追加案件の反応と新人の応募先が分かるようにする。
 *
 * ⚠ 2026-09-10：独立画面 `/entry-feed` から
 *   **エントリー一覧（/entries）の「🆕 新着（来た順）」タブ**へ引っ越した（メニューが長くなったため）。
 *   中身の正本＝`App\Support\EntryFeed`。古いURLは転送される（下の test_old_url_redirects）。
 */
class EntryFeedTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    private function project(array $attrs = [])
    {
        return ProjectFactory::new()->create(array_merge([
            'office'     => '東京',
            'start_date' => Carbon::today()->addDays(10)->format('Y-m-d'),
        ], $attrs));
    }

    /** 新しい順に並ぶ。 */
    public function test_rows_are_sorted_newest_first(): void
    {
        $old = PersonFactory::new()->staff()->create(['name' => '先に応募した人']);
        $new = PersonFactory::new()->staff()->create(['name' => 'あとで応募した人']);
        $p = $this->project();

        Application::create([
            'staff_id' => $old->id, 'project_id' => $p->id, 'intent' => '希望',
            'applied_at' => Carbon::now()->subDays(2),
        ]);
        Application::create([
            'staff_id' => $new->id, 'project_id' => $p->id, 'intent' => '希望',
            'applied_at' => Carbon::now()->subHour(),
        ]);

        $rows = collect(
            $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk()->original->getData()['feedRows']
        );

        $this->assertSame(['あとで応募した人', '先に応募した人'], $rows->pluck('staffName')->all());
    }

    /** 新人（入社1年未満）に印が付く。入社日が無い人には付けない。 */
    public function test_newcomer_is_flagged(): void
    {
        $rookie = PersonFactory::new()->staff()->create([
            'name' => '新人さん', 'hire_date' => Carbon::today()->subMonths(2)->format('Y-m-d'),
        ]);
        $veteran = PersonFactory::new()->staff()->create([
            'name' => 'ベテランさん', 'hire_date' => Carbon::today()->subYears(4)->format('Y-m-d'),
        ]);
        $p = $this->project();
        Application::create(['staff_id' => $rookie->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);
        Application::create(['staff_id' => $veteran->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);

        $data = $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk()->original->getData();
        $rows = collect($data['feedRows'])->keyBy('staffName');

        $this->assertTrue($rows['新人さん']['isNew']);
        $this->assertFalse($rows['ベテランさん']['isNew']);
        $this->assertSame(1, $data['feedNewCount']);
    }

    /** 「追加案件のみ」で絞れる。 */
    public function test_extra_filter(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $extra = $this->project(['category' => '追加案件']);
        $normal = $this->project(['category' => '通常案件']);
        Application::create(['staff_id' => $staff->id, 'project_id' => $extra->id, 'intent' => '希望', 'applied_at' => now()]);
        Application::create(['staff_id' => $staff->id, 'project_id' => $normal->id, 'intent' => '希望', 'applied_at' => now()]);

        $rows = collect(
            $this->actingAsPerson($this->emp())->get('/entries?view=feed&extra=1')->assertOk()->original->getData()['feedRows']
        );

        $this->assertSame([$extra->id], $rows->pluck('projectId')->all());
    }

    /** すでにアサイン済みの応募は「確定／仮」、それ以外は未対応として数える。 */
    public function test_assign_status_is_shown(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $p = $this->project();
        Application::create(['staff_id' => $staff->id, 'project_id' => $p->id, 'intent' => '希望', 'applied_at' => now()]);

        $before = $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk()->original->getData();
        $this->assertNull(collect($before['feedRows'])->first()['assignStatus']);
        $this->assertSame(1, $before['feedTodoCount']);

        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id,
            'date' => $p->start_date->format('Y-m-d'), 'role' => 'OP', 'status' => '確定',
        ]);

        $after = $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk()->original->getData();
        $this->assertSame('確定', collect($after['feedRows'])->first()['assignStatus']);
        $this->assertSame(0, $after['feedTodoCount']);
    }

    /** スタッフは入れない（社員以上の画面）。 */
    public function test_staff_cannot_open(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/entries?view=feed')->assertRedirect('/staff-portal');
    }
    /**
     * その日の稼働希望（終日〇／NG）が分かること（2026-09-03 baba要望）。
     *
     * ⚠ エントリー（応募）と稼働希望カレンダーは**別の入力**。
     *   両方見ないと「手は挙げてくれたが、その日はNGにしている」人に気づけない。
     *   ⚠ 出していない人（未定・未提出）は「—」＝〇でもNGでもないことを分けて出す。
     */
    public function test_the_feed_shows_the_shift_wish_for_that_day(): void
    {
        $day = Carbon::today()->addDays(10);
        $p = $this->project(['start_date' => $day->format('Y-m-d')]);

        $yes = PersonFactory::new()->staff()->create(['name' => 'マルの人']);
        $no = PersonFactory::new()->staff()->create(['name' => 'エヌジーの人']);
        $none = PersonFactory::new()->staff()->create(['name' => 'ダシテナイ人']);

        foreach ([$yes, $no, $none] as $s) {
            Application::create([
                'staff_id' => $s->id, 'project_id' => $p->id, 'intent' => '希望',
                'applied_at' => Carbon::now(),
            ]);
        }
        ShiftPreference::create([
            'staff_id' => $yes->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);
        ShiftPreference::create([
            'staff_id' => $no->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => 'NG',
        ]);

        $res = $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk();
        $rows = collect($res->original->getData()['feedRows'])->keyBy('staffName');

        $this->assertSame('ok', $rows['マルの人']['wish']);
        $this->assertSame('ng', $rows['エヌジーの人']['wish'], '手は挙げたのにNG、という食い違いに気づけません。');
        $this->assertNull($rows['ダシテナイ人']['wish'], '希望を出していない人まで〇／NGに見えてはいけません。');

        // 画面にも出ていること（列ごと消えたら誰も気づけない）。
        $res->assertSee('その日の希望')->assertSee('終日〇')->assertSee('⚠ NG');
    }

    /** 別の日のNGを、案件の日のNGと取りちがえないこと。 */
    public function test_a_wish_on_another_day_is_not_used(): void
    {
        $day = Carbon::today()->addDays(10);
        $p = $this->project(['start_date' => $day->format('Y-m-d')]);
        $s = PersonFactory::new()->staff()->create(['name' => 'ベツノヒの人']);

        Application::create([
            'staff_id' => $s->id, 'project_id' => $p->id, 'intent' => '希望',
            'applied_at' => Carbon::now(),
        ]);
        ShiftPreference::create([
            'staff_id' => $s->id, 'period' => $day->copy()->addDay()->format('Y-m'),
            'date' => $day->copy()->addDay()->format('Y-m-d'), 'availability' => 'NG',
        ]);

        $rows = collect(
            $this->actingAsPerson($this->emp())->get('/entries?view=feed')->assertOk()->original->getData()['feedRows']
        )->keyBy('staffName');

        $this->assertNull($rows['ベツノヒの人']['wish'], '別の日の希望を、案件の日のものとして出しています。');
    }

    /**
     * 古いURL（/entry-feed）を開いた人は、新しい場所（エントリー一覧の新着タブ）へ転送する。
     * ⚠ ブックマークや前に配った案内から来る人がいるので、この転送を消さないこと。
     *   絞り込み（期間・追加案件のみ・新人のみ・拠点）も落とさずに持っていく。
     */
    public function test_old_url_redirects_to_the_tab(): void
    {
        $this->actingAsPerson($this->emp())
            ->get('/entry-feed')
            ->assertRedirect('/entries?view=feed');

        $this->actingAsPerson($this->emp())
            ->get('/entry-feed?days=7&extra=1')
            ->assertRedirect('/entries?view=feed&days=7&extra=1');
    }

    /** 4つのタブが並んでいること（新着がメニューから消えたので、ここが唯一の入口）。 */
    public function test_the_entries_screen_has_four_tabs(): void
    {
        $this->actingAsPerson($this->emp())->get('/entries')
            ->assertOk()
            ->assertSee('📋 案件ごと')
            ->assertSee('🗓 月ごと')
            ->assertSee('📅 空いている人')
            ->assertSee('🆕 新着（来た順）');
    }
}
