<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D決め画面に「誰を並べるか」（2026-09-01 baba要望・報告）。
 *
 * 【何が起きていたか】
 * ⚠ この画面は出勤可能日（shift_preferences）を**まったく見ていなかった**。
 *   休みの見た目（CSSの .c-dayoff / .dc-offwarn）だけがあって中身が無く、
 *   **お休みの人がふつうに候補として並んでいた**。
 * ⚠ 拠点で絞っても、**一度でもその拠点の案件で担当に入った他拠点の人は残る**
 *   （サーバー側 keepIds。名前を出すために要る）。そこへ「イベプラなら毎日出す」が
 *   重なって、**福岡のイベプラが東京の全部の日に並んでいた**（babaの見立てどおり）。
 *
 * 【決めたこと】
 *  ・お休み（×／希望休）の人は並べない。件数だけ「お休み ◯名」と出す。
 *  ・他拠点の人は並べない。
 *  ・「＋全社員を表示」を押していないときは**イベプラだけ**（担当に入っていても）。
 *  ・⚠ ただし**お休みなのに担当に入っている人は出す**（赤い「休」印）。いちばん気づきたい間違いのため。
 */
class DirectorBoardFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create([
            'id' => 'E-ADM', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 社員に拠点（office）が渡っている＝画面が「他拠点の人」を見分けられる。 */
    public function test_each_employee_carries_its_office(): void
    {
        $admin = $this->admin();
        PersonFactory::new()->create([
            'id' => 'E-FUK', 'role' => 'employee', 'name' => '辻 太郎',
            'office' => '福岡', 'department' => 'イベプラ', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($admin)->get('/assign-director?office=東京')
            ->assertOk()
            ->assertViewHas('employees', function ($employees) {
                $me = collect($employees)->firstWhere('id', 'E-ADM');

                return $me && ($me['office'] ?? null) === '東京';
            });
    }

    /** お休み（×／希望休）が画面に渡っている。⚠ これまで一度も渡していなかった。 */
    public function test_the_day_off_map_is_passed_to_the_screen(): void
    {
        $admin = $this->admin();
        $day = Carbon::today()->copy()->addDays(3);

        ShiftPreference::create([
            'staff_id' => 'E-ADM', 'period' => $day->format('Y-m'),
            'date' => $day->toDateString(), 'availability' => 'NG',
        ]);
        // 「未定（△）」は休みにしない（入れるかもしれないため）。
        ShiftPreference::create([
            'staff_id' => 'E-ADM', 'period' => $day->format('Y-m'),
            'date' => $day->copy()->addDay()->toDateString(), 'availability' => '未定',
        ]);

        $this->actingAsPerson($admin)->get('/assign-director')
            ->assertOk()
            ->assertViewHas('dayOff', function ($dayOff) use ($day) {
                $mine = $dayOff['E-ADM'] ?? [];
                $ngKey = $day->year.'-'.$day->month.'-'.$day->day;
                $maybe = $day->copy()->addDay();
                $maybeKey = $maybe->year.'-'.$maybe->month.'-'.$maybe->day;

                return ($mine[$ngKey] ?? false) === true
                    && ! array_key_exists($maybeKey, $mine);
            });
    }

    /** 画面側の仕掛けが残っているか（@verbatim の中なので消えても気づけない）。 */
    public function test_the_screen_keeps_the_filters(): void
    {
        $admin = $this->admin();
        Project::create([
            'id' => 'P-DIR', 'project_name' => '運動会', 'content_names' => ['運動会'],
            'start_date' => Carbon::today()->copy()->addDays(3)->toDateString(),
            'office' => '東京', 'status' => '調整中',
        ]);

        $this->actingAsPerson($admin)->get('/assign-director')
            ->assertOk()
            // 他拠点を出さない
            ->assertSee('function sameOffice', false)
            // お休みを出さない＋件数を出す
            ->assertSee('function isDayOff', false)
            ->assertSee('function dayOffPeople', false)
            // ⚠ 数だけでなく名前も出す（2026-09-02 baba要望）。誰が休みか分からないと結局調べることになる。
            ->assertSee('お休み ${offList.length}名（${names}）', false)
            // カレンダーに前後の月の日も出す（前後の予定を見ながら決めるため）。
            // ⚠ ただし集計（担当バランス）は月単位のまま。
            ->assertSee('/ 7) + 1;', false)
            ->assertSee('いま見ている月ぶんだけ')
            // お休みなのに担当に入っている人の赤い印
            ->assertSee('dc-offwarn', false);
    }
}
