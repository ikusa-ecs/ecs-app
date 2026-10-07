<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ShiftPreference;
use App\Models\StaffRelation;
use App\Support\MonthAutoAssign;
use App\Support\NgPairs;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * NGペアが自動アサインで効くこと（2026-10-07 baba「月まとめ自動アサインでNGがちゃんと反映されてない」）。
 *
 *  ・**同じ回でいっしょに入れる人どうし**でもNGを見る（いちばん大きい穴だった）
 *  ・判定は**両方向**（片方の欄にだけ書いてあっても効く）。保存は一方通行のまま
 *  ・名前の空白（半角・全角）のちがいで、すり抜けない
 */
class NgPairsTest extends TestCase
{
    use RefreshDatabase;

    private function wish($staff, Carbon $day): void
    {
        ShiftPreference::create([
            'staff_id' => $staff->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => '稼働可',
        ]);
    }

    /** 名簿のNG欄に名前を書いたのと同じ形で保存する（partner_id は付けない＝書き方ゆれの確認用）。 */
    private function writeNg($staff, string $partnerName): void
    {
        StaffRelation::create([
            'staff_id' => $staff->id, 'partner_name' => $partnerName, 'relation_type' => 'NG',
        ]);
    }

    public function test_judged_both_ways_and_ignores_spaces(): void
    {
        $a = PersonFactory::new()->staff()->create(['name' => '池田 莉子']);
        $b = PersonFactory::new()->staff()->create(['name' => '木村 拓海']);
        $c = PersonFactory::new()->staff()->create(['name' => '佐藤 花']);
        // 名簿の氏名は全角の空白入りにしておく（作るときに空白が詰まることがあるので、あとから直接書く）。
        \Illuminate\Support\Facades\DB::table('people')->where('id', $b->id)->update(['name' => '木村　拓海']);
        $this->writeNg($a, '木村拓海');   // 空白なしで書いた・Aの欄にだけある

        $ng = NgPairs::load();

        $this->assertTrue($ng->isNg((string) $a->id, (string) $b->id));
        $this->assertTrue($ng->isNg((string) $b->id, (string) $a->id), '書いていない側からも効くこと');
        $this->assertFalse($ng->isNg((string) $a->id, (string) $c->id));
        $this->assertSame([$a->fresh()->name], $ng->partnerNames((string) $b->id));
        // 保存は一方通行のまま（Bの欄には足さない）。
        $this->assertSame(0, StaffRelation::where('staff_id', $b->id)->count());
    }

    /** 空の案件にまとめて入れるとき、NGどうしが2人とも入らない。 */
    public function test_month_auto_assign_does_not_put_ng_pair_together(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 3, 'office' => '東京',
        ]);
        $a = PersonFactory::new()->staff()->create(['name' => 'あ子', 'office' => '東京']);
        $b = PersonFactory::new()->staff()->create(['name' => 'い子', 'office' => '東京']);
        $c = PersonFactory::new()->staff()->create(['name' => 'う子', 'office' => '東京']);
        foreach ([$a, $b, $c] as $s) {
            $this->wish($s, $day);
        }
        $this->writeNg($b, 'あ子');   // い子の欄にだけ書いてある

        $plan = (new MonthAutoAssign($day->format('Y-m')))->plan();
        $names = collect($plan['projects'][0]['picks'])->pluck('name')->all();

        $this->assertCount(2, $names, '空いた1枠は空けたままにする');
        $this->assertFalse(in_array('あ子', $names, true) && in_array('い子', $names, true), 'NGどうしが同じ案件に入っている');
        $this->assertContains('う子', $names);
    }

    /** すでに入っている人のNG相手は、相手の欄にだけ書いてあっても入れない。 */
    public function test_existing_member_blocks_partner_written_on_member_side(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $p = ProjectFactory::new()->published()->create([
            'start_date' => $day->format('Y-m-d'), 'required_count' => 2, 'office' => '東京',
        ]);
        $a = PersonFactory::new()->staff()->create(['name' => 'あ子', 'office' => '東京']);
        $b = PersonFactory::new()->staff()->create(['name' => 'い子', 'office' => '東京']);
        $c = PersonFactory::new()->staff()->create(['name' => 'う子', 'office' => '東京']);
        $this->wish($b, $day);
        $this->wish($c, $day);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $a->id, 'date' => $day->format('Y-m-d'),
            'role' => '', 'status' => '仮',
        ]);
        $this->writeNg($a, 'い子');   // すでに入っている あ子 の欄にだけ書いてある

        $plan = (new MonthAutoAssign($day->format('Y-m'), null, [], [], [$p->id]))->plan();
        $names = collect($plan['projects'][0]['picks'] ?? [])->pluck('name')->all();

        $this->assertSame(['う子'], $names, 'い子（あ子とNG）でなく、う子が入ること');
    }
}
