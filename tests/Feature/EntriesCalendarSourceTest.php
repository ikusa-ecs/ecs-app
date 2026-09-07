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
 * エントリー一覧に「その日 終日〇を出しているだけの人」も出すことを守るテスト
 * （2026-09-07 baba要望）。
 *
 * 【なぜ要るか】
 * エントリー（案件に手を挙げた）と、稼働希望カレンダーの〇（その日は働けます）は**別の入力**。
 * これまで案件ごとの一覧には前者しか出ておらず、
 * 「カレンダーで〇にしてくれている人」に声を掛けようとしても、この画面から探せなかった。
 *
 * ⚠ どちらから来た人かは必ず区別する（src）。混ぜると声を掛ける優先度を取り違える。
 *     src='entry' … その案件にエントリーした人
 *     src='cal'   … その日に終日〇を出しているだけ（この案件には応募していない）
 * ⚠ 並び順は「エントリーした人が先」。混ぜると手を挙げてくれた人が埋もれる。
 * ⚠ 出す人の決まりは「📅 空いている人」カレンダーと同じ（スタッフ・在籍中・拠点）。
 */
class EntriesCalendarSourceTest extends TestCase
{
    use RefreshDatabase;

    private function entrantsOfFirstCase($me, string $projectId): array
    {
        $cases = collect(
            $this->actingAsPerson($me)->get('/entries')->assertOk()->original->getData()['entriesCases']
        )->keyBy('id');

        return $cases[$projectId]['entrants'] ?? [];
    }

    /** 終日〇を出しているだけの人が、候補として出る（src='cal'）。 */
    public function test_calendar_only_staff_appear(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $applicant = PersonFactory::new()->staff()->create(['name' => '応募した人', 'office' => '東京']);
        $calOnly = PersonFactory::new()->staff()->create(['name' => '〇だけの人', 'office' => '東京']);

        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京']);

        Application::create(['project_id' => $p->id, 'staff_id' => $applicant->id, 'intent' => '希望']);
        foreach ([$applicant, $calOnly] as $s) {
            ShiftPreference::create([
                'staff_id' => $s->id, 'period' => $day->format('Y-m'),
                'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
            ]);
        }

        $rows = collect($this->entrantsOfFirstCase($me, $p->id))->keyBy('name');

        $this->assertSame('entry', $rows['応募した人']['src'] ?? null);
        $this->assertSame('cal', $rows['〇だけの人']['src'] ?? null);
        // 〇だけの人にも「その日の希望」は 'ok' が入る（画面がそのまま出せるように）。
        $this->assertSame('ok', $rows['〇だけの人']['wish'] ?? null);
    }

    /** ⚠ エントリーした人が先に並ぶ（あとから〇だけの人が続く）。 */
    public function test_applicants_come_first(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $calOnly = PersonFactory::new()->staff()->create(['name' => 'あ〇だけ', 'office' => '東京']);
        $applicant = PersonFactory::new()->staff()->create(['name' => 'わ応募', 'office' => '東京']);

        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京']);
        Application::create(['project_id' => $p->id, 'staff_id' => $applicant->id, 'intent' => '希望']);
        ShiftPreference::create([
            'staff_id' => $calOnly->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);

        $names = collect($this->entrantsOfFirstCase($me, $p->id))->pluck('name')->all();

        $this->assertSame(['わ応募', 'あ〇だけ'], $names);
    }

    /** ⚠ 同じ人を二重に出さない（エントリーもしていて〇も出している人）。 */
    public function test_no_duplicate_when_both(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $both = PersonFactory::new()->staff()->create(['name' => '両方の人', 'office' => '東京']);

        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京']);
        Application::create(['project_id' => $p->id, 'staff_id' => $both->id, 'intent' => '希望']);
        ShiftPreference::create([
            'staff_id' => $both->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);

        $rows = collect($this->entrantsOfFirstCase($me, $p->id))->where('name', '両方の人');

        $this->assertCount(1, $rows, '二重に出さないこと');
        $this->assertSame('entry', $rows->first()['src'], 'エントリーのほうを優先すること');
    }

    /** 〇だけの人でも、もうアサインされていればその状態が分かる。 */
    public function test_assigned_state_is_kept(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $s = PersonFactory::new()->staff()->create(['name' => '〇でアサイン済', 'office' => '東京']);

        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京']);
        ShiftPreference::create([
            'staff_id' => $s->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $s->id,
            'date' => $day->format('Y-m-d'), 'role' => 'OP', 'status' => '仮',
        ]);

        $row = collect($this->entrantsOfFirstCase($me, $p->id))->firstWhere('name', '〇でアサイン済');

        $this->assertSame('cal', $row['src']);
        $this->assertTrue($row['assigned']);
        $this->assertSame('仮', $row['status']);
    }

    /** ⚠ NG（希望休）の人は出さない（〇の人だけを候補にする）。 */
    public function test_ng_staff_do_not_appear(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);
        $ng = PersonFactory::new()->staff()->create(['name' => 'NGの人', 'office' => '東京']);

        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京']);
        ShiftPreference::create([
            'staff_id' => $ng->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => 'NG',
        ]);

        $names = collect($this->entrantsOfFirstCase($me, $p->id))->pluck('name')->all();

        $this->assertNotContains('NGの人', $names);
    }

    /** 画面に「出どころ」の絞り込みと印が出ていること。 */
    public function test_the_screen_shows_the_source(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);

        $this->actingAsPerson($me)->get('/entries')
            ->assertOk()
            ->assertSee('id="fSrc"', false)
            ->assertSee('id="fOnDate"', false)
            ->assertSee('id="fStaff"', false)
            ->assertSee('function srcTag(e){', false);
    }

    /**
     * 月ごとの表の決まりを守る（2026-09-07 baba要望）。
     *
     * ⚠ ✕（NG）＝エントリーはあるが、その日は本人がNG（希望休）。
     *   これまで〇（エントリー中）と同じ見た目だったので、**気づかずアサインしていた**。
     * ⚠ 「関係者だけ」＝「・」と「✕」しかない人の行を隠す。隠すのは行の表示だけで、
     *   応募数の数え方は変えない。
     */
    public function test_month_view_marks_and_filter_exist(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);

        $this->actingAsPerson($me)->get('/entries')
            ->assertOk()
            ->assertSee('id="fOnlyRelated"', false)
            // NG は 〇 と別の印にする
            ->assertSee("e.wish === 'ng' ? 'ng'", false)
            ->assertSee('m-ng', false)
            // ⚠ 改行は String.fromCharCode(10)。「\」＋「n」と書くと置換で本物の改行に化けて
            //   この画面の JavaScript が丸ごと死ぬ（過去に何度も起きている）。
            ->assertSee('String.fromCharCode(10)', false);
    }

    /** ⚠ 「空」のマスもクリックで仮アサインできること（押せないと候補に出す意味がない）。 */
    public function test_calendar_cells_are_clickable(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京']);

        $this->actingAsPerson($me)->get('/entries')
            ->assertOk()
            ->assertSee("state === 'ent' || state === 'cal'", false);
    }
}
