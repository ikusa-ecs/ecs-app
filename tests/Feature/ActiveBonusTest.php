<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Support\ActiveBonus;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 繁忙期ボーナス（/active-bonus）。2026-09-17 baba要望。
 *
 * 見本の画像から逆算した計算のきまりを、そのまま見張る：
 *   ・階層に届くと時給が上がり、**その月の全回数にさかのぼって**ボーナスが付く
 *   ・ボーナス額＝回数 × 1回の時間 × 上がり幅
 *   ・削減見込額＝タイミー利用時コスト − ボーナス合計
 *
 * ⚠ 数えるのは「確定」だけ。仮・キャンセルを数えると、払っていないお金が画面に出る。
 * ⚠ 対象はスタッフだけ（社員は入らない）。
 */
class ActiveBonusTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        // 月をまたぐ取り違えを見るため、月の中ほどを基準にする。
        $this->month = Carbon::today()->startOfMonth();
    }

    private function person(string $id, string $role = 'staff', string $office = '東京'): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $id.'さん', 'role' => $role,
            'permission' => $role === 'staff' ? 'staff' : 'employee',
            'office' => $office, 'must_onboard' => false, 'active' => true,
        ]);
    }

    /** その人に、その月ぶんの確定アサインを $n 回作る。 */
    private function assignTimes(string $staffId, int $n, string $status = '確定', string $office = '東京'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $day = (clone $this->month)->addDays($i);
            // ⚠ 案件IDは状態ごとに分ける（同じ人に「確定」と「仮」を作るとIDがぶつかるため）。
            $pid = 'P-'.$staffId.'-'.($status === '確定' ? 'K' : 'T').$i;
            ProjectFactory::new()->create([
                'id' => $pid, 'project_name' => $pid, 'office' => $office,
                'start_date' => $day->format('Y-m-d'), 'required_count' => 5,
            ]);
            Assignment::create([
                'project_id' => $pid, 'staff_id' => $staffId, 'role' => 'OP',
                'date' => $day->format('Y-m-d'), 'status' => $status,
            ]);
        }
    }

    // ────────────────────────────────── 計算そのもの

    public function test_階層に届くと全回数にさかのぼってボーナスが付く(): void
    {
        // 既定＝5回で+500／10回で+1,000／15回で+1,500・1回6時間。
        // 9回の人は「5回」の階層 → 9 × 6 × 500 = 27,000円（見本の関根さんと同じ）。
        $this->assertSame(500, ActiveBonus::rateFor(9));
        $this->assertSame(27000, ActiveBonus::bonusFor(9));

        // 15回の人は 15 × 6 × 1,500 = 135,000円（見本の大木さんと同じ）。
        $this->assertSame(135000, ActiveBonus::bonusFor(15));

        // 11回の人は「10回」の階層 → 11 × 6 × 1,000 = 66,000円（見本の小助川さんと同じ）。
        $this->assertSame(66000, ActiveBonus::bonusFor(11));
    }

    public function test_階層に届いていない人はゼロ円(): void
    {
        $this->assertSame(0, ActiveBonus::rateFor(4));
        $this->assertSame(0, ActiveBonus::bonusFor(4));
    }

    public function test_あと1回の人が分かる(): void
    {
        $this->assertTrue(ActiveBonus::isOneMore(4));   // あと1回で5回
        $this->assertTrue(ActiveBonus::isOneMore(9));   // あと1回で10回
        $this->assertFalse(ActiveBonus::isOneMore(7));  // あと3回
        $this->assertFalse(ActiveBonus::isOneMore(15)); // いちばん上まで届いている
    }

    public function test_いちばん上まで届いたら次の階層は無い(): void
    {
        $this->assertNull(ActiveBonus::nextTier(15));
        $this->assertNull(ActiveBonus::nextTier(30));
    }

    // ────────────────────────────────── 月ぶんの集計

    public function test_確定だけ数える(): void
    {
        $this->person('S-001');
        $this->assignTimes('S-001', 5, '確定');
        $this->assignTimes('S-001', 4, '仮');

        $sum = ActiveBonus::summary($this->month->format('Y-m'), '東京');

        // 仮の4回を数えると9回になってしまう。確定の5回だけ。
        $this->assertSame(5, $sum['ranking'][0]['count']);
        $this->assertSame(15000, $sum['ranking'][0]['bonus']);   // 5 × 6 × 500
    }

    public function test_社員は対象に入らない(): void
    {
        $this->person('E-010', 'employee');
        $this->assignTimes('E-010', 6, '確定');

        $sum = ActiveBonus::summary($this->month->format('Y-m'), '東京');

        $this->assertSame(0, $sum['targetCount']);
        $this->assertSame(0, $sum['bonusTotal']);
    }

    public function test_その月に1回も入っていない人は対象に入らない(): void
    {
        $this->person('S-001');
        $this->person('S-002');       // 1回も入っていない
        $this->assignTimes('S-001', 3, '確定');

        $sum = ActiveBonus::summary($this->month->format('Y-m'), '東京');

        // 0回の人を並べると達成率が意味を持たなくなるので、対象は1名。
        $this->assertSame(1, $sum['targetCount']);
    }

    public function test_先月のアサインは今月に数えない(): void
    {
        $this->person('S-001');
        $prev = (clone $this->month)->subMonthNoOverflow();

        ProjectFactory::new()->create([
            'id' => 'P-PREV', 'project_name' => 'P-PREV', 'office' => '東京',
            'start_date' => $prev->format('Y-m-d'), 'required_count' => 5,
        ]);
        Assignment::create([
            'project_id' => 'P-PREV', 'staff_id' => 'S-001', 'role' => 'OP',
            'date' => $prev->format('Y-m-d'), 'status' => '確定',
        ]);

        $sum = ActiveBonus::summary($this->month->format('Y-m'), '東京');

        $this->assertSame(0, $sum['targetCount']);
    }

    public function test_拠点はスタッフの所属で分ける(): void
    {
        $this->person('S-001', 'staff', '東京');
        $this->person('S-002', 'staff', '大阪');
        $this->assignTimes('S-001', 5, '確定');
        $this->assignTimes('S-002', 5, '確定', '大阪');

        $tokyo = ActiveBonus::summary($this->month->format('Y-m'), '東京');
        $all = ActiveBonus::summary($this->month->format('Y-m'), null);

        $this->assertSame(1, $tokyo['targetCount']);
        $this->assertSame(2, $all['targetCount']);
    }

    public function test_削減見込額はタイミー費用からボーナスを引いた額(): void
    {
        ActiveBonus::save([
            ['count' => 5, 'rate' => 500],
            ['count' => 10, 'rate' => 1000],
            ['count' => 15, 'rate' => 1500],
        ], 6, 10000, true);

        $this->person('S-001');
        $this->assignTimes('S-001', 10, '確定');

        $sum = ActiveBonus::summary($this->month->format('Y-m'), '東京');

        $this->assertSame(60000, $sum['bonusTotal']);            // 10 × 6 × 1,000
        $this->assertSame(100000, $sum['spotTotal']);            // 10回 × 10,000円
        $this->assertSame(40000, $sum['saving']);                // 100,000 − 60,000
    }

    // ────────────────────────────────── 設定

    public function test_設定した階層が計算に効く(): void
    {
        ActiveBonus::save([['count' => 3, 'rate' => 200]], 4, 5000, true);

        $this->assertSame(200, ActiveBonus::rateFor(3));
        $this->assertSame(0, ActiveBonus::rateFor(2));
        $this->assertSame(2400, ActiveBonus::bonusFor(3));   // 3 × 4 × 200
        $this->assertSame(5000, ActiveBonus::spotCost());
        $this->assertTrue(ActiveBonus::enabled());
    }

    public function test_設定を保存すると回数の少ない順に並ぶ(): void
    {
        ActiveBonus::save([
            ['count' => 15, 'rate' => 1500],
            ['count' => 5, 'rate' => 500],
        ], 6, 10000, false);

        $tiers = ActiveBonus::tiers();
        $this->assertSame(5, $tiers[0]['count']);
        $this->assertSame(15, $tiers[1]['count']);
        $this->assertFalse(ActiveBonus::enabled());
    }

    // ────────────────────────────────── 画面

    public function test_画面が開く(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $this->person('S-001');
        $this->assignTimes('S-001', 9, '確定');

        $this->actingAsPerson($me)->get('/active-bonus')
            ->assertOk()
            ->assertSee('繁忙期ボーナス')
            ->assertSee('あと1回でボーナス')
            ->assertSee('S-001さん')
            ->assertSee('¥27,000');   // 9 × 6 × 500
    }

    public function test_スタッフは社員の画面に入れない(): void
    {
        $staff = $this->person('S-001');

        // 金額（会社のコスト・削減額）が出る画面なので、スタッフは入れない。
        $this->actingAsPerson($staff)->get('/active-bonus')->assertRedirect();
    }

    public function test_実施中にしたときだけ左メニューに出る(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        ActiveBonus::save([['count' => 5, 'rate' => 500]], 6, 10000, false);
        $this->actingAsPerson($me)->get('/dashboard')->assertDontSee('繁忙期ボーナス');

        ActiveBonus::save([['count' => 5, 'rate' => 500]], 6, 10000, true);
        $this->actingAsPerson($me)->get('/dashboard')->assertSee('繁忙期ボーナス');
    }

    public function test_スタッフ画面には実施中のときだけ自分の回数が出る(): void
    {
        $staff = $this->person('S-001');
        $this->assignTimes('S-001', 4, '確定');

        // ⚠ 「繁忙期ボーナス」という語は画面の書式（CSS）のコメントにも出るので、
        //   出ているかどうかは**中身の文言**で見る（枠が出ていないことを確かめたいため）。
        // 実施していないあいだは出さない（約束していないお金の話をしない）。
        ActiveBonus::save([['count' => 5, 'rate' => 500]], 6, 10000, false);
        $this->actingAsPerson($staff)->get('/staff-portal')->assertDontSee('今月のアサイン');

        // 実施中＝自分の回数と「あと1回」が出る。
        ActiveBonus::save([['count' => 5, 'rate' => 500]], 6, 10000, true);
        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('今月のアサイン')
            ->assertSee('あと1回');
    }

    public function test_共通設定から保存できる(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($me)->post('/settings/active-bonus', [
            'counts' => [3, 8, null],
            'rates' => [300, 800, null],
            'hours' => 5,
            'spot_cost' => 9000,
            'enabled' => '1',
        ])->assertRedirect();

        $tiers = ActiveBonus::tiers();
        $this->assertCount(2, $tiers);
        $this->assertSame(3, $tiers[0]['count']);
        $this->assertSame(800, $tiers[1]['rate']);
        $this->assertSame(5, ActiveBonus::hours());
        $this->assertSame(9000, ActiveBonus::spotCost());
        $this->assertTrue(ActiveBonus::enabled());
    }
}
