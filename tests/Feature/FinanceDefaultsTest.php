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
 *   ・アサインが1人もいない案件は数を出さない（＝画面は運営人数を仮に入れる）
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

    public function test_収支入力の画面に人数と高速費の選択肢が渡る(): void
    {
        $me = PersonFactory::new()->create();
        $p = ProjectFactory::new()->create(['start_date' => now()->format('Y-m-d'), 'sales_owners' => [$me->name]]);
        $s1 = PersonFactory::new()->staff()->create();
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'OP', 'date' => now()->format('Y-m-d'), 'status' => '確定']);

        $this->actingAsPerson($me)->get('/mypage-finance')
            ->assertOk()
            ->assertSee('ECS_STAFF_COUNTS', false)
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
