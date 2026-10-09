<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentDifficulty;
use App\Models\Person;
use App\Models\ShiftPreference;
use App\Support\RookieDifficulty;
use App\Support\RookieFcPlan;
use App\Support\Rookies;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 新人ページ（2026-10-09 baba要望・正本=Rookies／RookieDifficulty／RookieFcPlan）。
 * 決めたこと：案を出すのはFCだけ（Dは人が決める）／新人は卒業ボタンで外す／
 * FCに入れるのは運営人数に空きがある案件・1案件に新人1人・その月のFC目標（イベント数−D数）まで。
 */
class RookieTest extends TestCase
{
    use RefreshDatabase;

    /** シートの書き方（見出しに改行・●／◻︎の印・右に別の表）をそのまま再現した抜粋。 */
    private const REAL_CSV = ",,,,,,,\n"
        .",コンテンツ名,難易度,\"新人\nD必修\",\"新人\nD推奨\",\"セールス\nD推奨\",,\n"
        ."達成目安,ー,ー,6ヶ月,12ヶ月,,,暦\n"
        ."謎解き脱出,ある会議室からの脱出,1,●,●,,,1ヶ月目\n"
        .",秘密の会議室からの脱出,3,,,,,2ヶ月目\n"
        ."運動会,戦国運動会,3,◻︎,◻︎,,,\n"
        .",NEW運動会,3,◻︎,◻︎,,,\n"
        .",混乱する捜査会議からの脱出\t\t\t,3,,●,,,\n"
        .",D数,,10,17,,,\n";

    public function test_シートのCSVを読む(): void
    {
        $rows = RookieDifficulty::parse(self::REAL_CSV);

        $this->assertCount(5, $rows);
        $this->assertSame(['sheet_name' => 'ある会議室からの脱出', 'category' => '謎解き脱出', 'difficulty' => 1,
            'must' => true, 'recommend' => true, 'one_of' => false], $rows[0]);
        $this->assertSame('運動会', $rows[3]['category'], '分類は下の行に引き継ぐ');
        $this->assertTrue($rows[3]['one_of'], '◻︎＝同じ分類から1つ');
        $this->assertSame('混乱する捜査会議からの脱出', $rows[4]['sheet_name'], '後ろのタブは外す');
    }

    public function test_取込で名前が合えば台帳につなぐ(): void
    {
        Content::create(['id' => 'CT-1', 'content_name' => 'ある会議室からの脱出', 'active' => true]);
        $r = RookieDifficulty::import(self::REAL_CSV, RookieDifficulty::REAL);

        $this->assertSame(5, $r['saved']);
        $this->assertSame('CT-1', ContentDifficulty::where('sheet_name', 'ある会議室からの脱出')->value('content_id'));
        $this->assertNull(ContentDifficulty::where('sheet_name', '秘密の会議室からの脱出')->value('content_id'), '合わない名前は勘でつながない');
    }

    public function test_新人は自動で出て卒業で消える(): void
    {
        $new = PersonFactory::new()->create(['role' => 'employee', 'department' => 'イベプラ', 'hire_date' => Carbon::today()->subMonths(3), 'office' => '東京']);
        PersonFactory::new()->create(['role' => 'employee', 'department' => 'イベプラ', 'hire_date' => Carbon::today()->subYears(5), 'office' => '東京']);
        PersonFactory::new()->create(['role' => 'employee', 'department' => 'クリエイティブ', 'hire_date' => Carbon::today()->subMonths(3), 'office' => '東京']);

        $this->assertSame([$new->id], Rookies::list()->pluck('id')->all());
        $this->assertSame(4, Rookies::monthNo($new, Carbon::today()->startOfMonth()));

        $new->rookie_state = Rookies::OUT;
        $new->save();
        $this->assertSame([], Rookies::list()->pluck('id')->all());
    }

    public function test_FCの案は空きのある案件に目標まで(): void
    {
        $day = Carbon::today()->addDays(1);
        if (! $day->isSameMonth(Carbon::today())) {
            $this->markTestSkipped('月末は翌月になるので見ない');
        }
        $rookie = PersonFactory::new()->create(['name' => '新人A', 'role' => 'employee', 'department' => 'イベプラ',
            'hire_date' => Carbon::today()->startOfMonth(), 'office' => '東京']);   // 1ヶ月目＝FC目標10
        $open = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $day->format('Y-m-d'), 'required_count' => 3, 'status' => '調整中']);
        $full = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $day->format('Y-m-d'), 'required_count' => 1, 'status' => '調整中']);
        $staff = PersonFactory::new()->create(['office' => '東京']);
        Assignment::create(['project_id' => $full->id, 'staff_id' => $staff->id, 'role' => 'OP', 'status' => '確定', 'date' => $day->format('Y-m-d')]);

        $plan = RookieFcPlan::build(Carbon::today(), '東京');
        $this->assertSame([$open->id], array_column($plan['picks'], 'projectId'), '満員の案件には入れない・同じ日は1件');
        $this->assertSame('新人A', $plan['picks'][0]['rookie']);
        $this->assertSame(10, $plan['rookies'][0]['target']['fc']);

        // 出勤可能日が×なら入れない。
        ShiftPreference::create(['staff_id' => $rookie->id, 'period' => $day->format('Y-m'), 'date' => $day->format('Y-m-d'), 'availability' => 'NG']);
        $this->assertSame([], RookieFcPlan::build(Carbon::today(), '東京')['picks']);
    }

    /** 1案件に入れる新人＝ふつう1人／8名を超えると2人／大型で12名以上は何人でも（2026-10-09 baba）。 */
    public function test_1案件に入れる新人の数(): void
    {
        $p = fn (int $n, string $scale = '') => ProjectFactory::new()->make(['required_count' => $n, 'scale' => $scale]);

        $this->assertSame(1, RookieFcPlan::capFor($p(8), 8));
        $this->assertSame(2, RookieFcPlan::capFor($p(9), 9));
        $this->assertSame(2, RookieFcPlan::capFor($p(12, '中型'), 12));
        $this->assertSame(2, RookieFcPlan::capFor($p(11, '大型'), 11));
        $this->assertGreaterThan(100, RookieFcPlan::capFor($p(12, '大型'), 12));

        $day = Carbon::today()->addDays(1);
        if (! $day->isSameMonth(Carbon::today())) {
            $this->markTestSkipped('月末は翌月になるので見ない');
        }
        foreach (['A', 'B', 'C'] as $n) {
            PersonFactory::new()->create(['name' => '新人'.$n, 'role' => 'employee', 'department' => 'イベプラ',
                'hire_date' => Carbon::today()->startOfMonth(), 'office' => '東京']);
        }
        $big = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $day->format('Y-m-d'), 'required_count' => 10, 'status' => '調整中']);

        $picks = RookieFcPlan::build(Carbon::today(), '東京')['picks'];
        $this->assertCount(2, array_filter($picks, fn ($x) => $x['projectId'] === $big->id), '8名を超える案件は2人まで');
    }

    public function test_画面と卒業ボタン(): void
    {
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $new = PersonFactory::new()->create(['name' => '新人B', 'role' => 'employee', 'department' => 'セールス',
            'hire_date' => Carbon::today()->subMonth(), 'office' => '東京']);

        $this->actingAsPerson($me)->get('/rookies')->assertOk()->assertSee('新人B');
        // OJT担当とメモ（2026-10-09 baba要望）。
        $this->actingAsPerson($me)->post('/rookies/ojt', ['id' => $new->id, 'ojt' => $me->id, 'note' => '水曜はNG'])->assertRedirect();
        $this->assertSame([$me->id, '水曜はNG'], [$new->fresh()->rookie_ojt_id, $new->fresh()->rookie_note]);
        $this->actingAsPerson($me)->post('/rookies/state', ['id' => $new->id, 'state' => 'out'])->assertRedirect();
        $this->assertSame('out', $new->fresh()->rookie_state);

        // 社員なら直せる（新人が自分で直せるように・2026-10-09 baba）／スタッフは入れない。
        $emp = PersonFactory::new()->create(['permission' => 'employee', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($emp)->post('/rookies/state', ['id' => $new->id, 'state' => 'in'])->assertRedirect();
        $this->assertSame('in', $new->fresh()->rookie_state);
        $staff = PersonFactory::new()->create(['role' => 'staff', 'permission' => 'staff', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($staff)->post('/rookies/state', ['id' => $new->id, 'state' => 'out']);
        $this->assertSame('in', $new->fresh()->rookie_state, 'スタッフは直せない（スタッフ画面へ戻される）');
    }

    /** 経験を手で直す（2026-10-09 baba「大型で受付だったから実は経験していない」）。 */
    public function test_経験を手で直す(): void
    {
        Content::create(['id' => 'CT-1', 'content_name' => '戦国運動会', 'active' => true]);
        ContentDifficulty::create(['kind' => 'リアル', 'sheet_name' => '戦国運動会', 'difficulty' => 3, 'must' => true, 'content_id' => 'CT-1']);
        $new = PersonFactory::new()->create(['name' => '新人C', 'role' => 'employee', 'department' => 'イベプラ',
            'hire_date' => Carbon::today()->subMonths(2), 'office' => '東京']);
        $past = ProjectFactory::new()->create(['office' => '東京', 'start_date' => Carbon::today()->subDays(10)->format('Y-m-d'), 'content_ids' => ['CT-1']]);
        Assignment::create(['project_id' => $past->id, 'staff_id' => $new->id, 'role' => 'UKE', 'status' => '確定',
            'date' => $past->start_date->format('Y-m-d')]);

        $exp = fn () => collect(RookieFcPlan::build(Carbon::today(), '東京')['rookies'])->firstWhere('id', $new->id);
        $this->assertSame(['戦国運動会'], $exp()['progress']['must']['readyD'], '自動ではFC済＝D準備OK');

        $me = PersonFactory::new()->create(['permission' => 'employee', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($me)->post('/rookies/exp', ['id' => $new->id, 'ov' => ['CT-1' => ['fc' => 'none', 'd' => '']]])->assertRedirect();
        $this->assertSame(['CT-1' => ['fc' => 'none']], $new->fresh()->rookie_overrides);
        $this->assertSame(['戦国運動会'], $exp()['progress']['must']['left'], 'やっていないにしたら「まだ」に戻る');
        $this->assertSame(1, $exp()['exp'][0]['fcAuto'], '自動の回数はそのまま見える');
    }
}
