<?php

namespace Tests\Feature;

use App\Http\Controllers\MyPageFinanceController;
use App\Models\Assignment;
use App\Support\FinanceItems;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 収支入力の「はじめに入る数字」（FBシート No.28・No.30／2026-11-12）。
 *   ・当日スタッフ費の人数＝アサインされているスタッフだけ（社員・キャンセルは数えない）
 *   ・アサインが1人もいない案件は数を出さない（＝画面は仮の数量を入れない・2026-11-13）
 *   ・高速費＝片道のキロ数→往復の金額（1kmきざみ）
 */
class FinanceDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_当日スタッフ費の人数は社員とキャンセルを数えない(): void
    {
        $p = ProjectFactory::new()->create(['start_date' => '2026-11-20', 'required_count' => 5]);
        $empty = ProjectFactory::new()->create(['start_date' => '2026-11-21', 'required_count' => 4]);
        $onlyEmp = ProjectFactory::new()->create(['start_date' => '2026-11-22', 'required_count' => 2]);

        $emp = PersonFactory::new()->create();
        $s1 = PersonFactory::new()->staff()->create();
        $s2 = PersonFactory::new()->staff()->create();
        $s3 = PersonFactory::new()->staff()->create();

        foreach ([[$emp, 'D', '確定'], [$s1, 'OP', '確定'], [$s2, 'FC', '確定'], [$s3, 'OP', 'キャンセル']] as [$who, $role, $status]) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'role' => $role, 'date' => '2026-11-20', 'status' => $status]);
        }
        Assignment::create(['project_id' => $onlyEmp->id, 'staff_id' => $emp->id, 'role' => 'D', 'date' => '2026-11-22', 'status' => '確定']);

        $counts = MyPageFinanceController::staffCounts([$p->id, $empty->id, $onlyEmp->id]);

        $this->assertSame(2, $counts[$p->id]);                 // スタッフ2人だけ（社員1・キャンセル1は外れる）
        $this->assertArrayNotHasKey($empty->id, $counts);      // アサイン無し＝出さない（画面は運営人数）
        $this->assertSame(0, $counts[$onlyEmp->id]);           // 社員だけの案件＝0人
    }

    /** 飲食費・宿泊費の仮の数量（FBシート No.28 後半・2026-11-13 小沼さん決定＝社員も数える・宿泊は全員×泊まる回数）。 */
    public function test_飲食費と宿泊費に使う人数は社員も数え泊まる回数は前泊と後泊で数える(): void
    {
        $p = ProjectFactory::new()->create(['start_date' => '2026-11-20']);
        $empty = ProjectFactory::new()->create(['start_date' => '2026-11-21']);
        $emp = PersonFactory::new()->create();
        $s1 = PersonFactory::new()->staff()->create();
        $s2 = PersonFactory::new()->staff()->create();
        foreach ([[$emp, 'D', '確定'], [$s1, 'OP', '確定'], [$s2, 'OP', 'キャンセル']] as [$who, $role, $status]) {
            Assignment::create(['project_id' => $p->id, 'staff_id' => $who->id, 'role' => $role, 'date' => '2026-11-20', 'status' => $status]);
        }
        // 同じ人が別の日にも入っている（リハ日など）＝1人として数える。
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'OP', 'date' => '2026-11-19', 'status' => '確定']);

        $counts = MyPageFinanceController::headCounts([$p->id, $empty->id]);
        $this->assertSame(2, $counts[$p->id]);              // 社員1＋スタッフ1（同じ人が2日入っていても1人・キャンセルは外れる）
        $this->assertArrayNotHasKey($empty->id, $counts);   // アサイン無し＝出さない（画面は運営人数）

        // 見出しに出す「誰がいたか」＝社員が先・同じ人は1回・キャンセルは出さない。
        $roster = MyPageFinanceController::roster([$p->id, $empty->id]);
        $this->assertSame([$emp->name, $s1->name], array_column($roster[$p->id], 'name'));
        $this->assertSame([true, false], array_column($roster[$p->id], 'emp'));
        $this->assertArrayNotHasKey($empty->id, $roster);

        $nights = MyPageFinanceController::stayNights(['a' => '前泊有', 'b' => '後泊あり', 'c' => '前後泊', 'd' => '無', 'e' => null]);
        $this->assertSame(1, $nights['a']);
        $this->assertSame(1, $nights['b']);
        $this->assertSame(2, $nights['c'], '前後泊＝前泊と後泊の2回');
        $this->assertArrayNotHasKey('d', $nights);
        $this->assertArrayNotHasKey('e', $nights);
    }

    public function test_収支入力の画面に人数と高速費の選択肢が渡る(): void
    {
        $me = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => now()->format('Y-m-d'), 'sales_owners' => [$me->name]]);
        $s1 = PersonFactory::new()->staff()->create();
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'OP', 'date' => now()->format('Y-m-d'), 'status' => '確定']);

        $this->actingAsPerson($me)->get('/mypage-finance')
            ->assertOk()
            ->assertSee('ECS_STAFF_COUNTS', false)
            ->assertSee('ECS_HEAD_COUNTS', false)
            ->assertSee('ECS_STAY_NIGHTS', false)
            ->assertSee('ECS_ROSTER', false)
            ->assertSee($s1->name, false)
            ->assertSee('"'.$p->id.'":1', false)
            ->assertSee('km-input', false);
    }

    public function test_高速費は片道のキロ数から往復の金額を出す(): void
    {
        $this->assertSame(3600, FinanceItems::highwayRoundTrip(30));     // 片道30km＝1,800 → 往復3,600
        $this->assertSame(120, FinanceItems::highwayRoundTrip(1));       // 1kmきざみ
        $this->assertSame(37560, FinanceItems::highwayRoundTrip(313));   // 210kmより遠くても出る

        $highway = collect(FinanceItems::all())->firstWhere('key', 'highway');
        $this->assertSame(120, $highway['perKm']);
        // 合計の計算は今までどおり（金額を1,000円単位に切り上げ）
        $this->assertSame(38000, FinanceItems::costTotal(['highway' => ['amount' => 37560]]));
    }
}
