<?php

namespace Tests\Feature;

use App\Models\ShiftPreference;
use App\Support\MonthAutoAssign;
use App\Support\StayDayCheck;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 前泊の前日・後泊の翌日が×の人（2026-10-14・FBシート No.23 桑江さん／baba＝A案）。
 * 正本＝App\Support\StayDayCheck。
 *   ・前泊＝前日、後泊＝翌日も見る。NG・希望休なら札。未定・未入力は札なし。
 *   ・候補からは外さない（札だけ）。自動アサイン（案件別・月まとめ・日別ボード）では選ばない。
 */
class StayDayCheckTest extends TestCase
{
    use RefreshDatabase;

    private function pref($staff, Carbon $day, string $v): void
    {
        ShiftPreference::create(['staff_id' => $staff->id, 'period' => $day->format('Y-m'),
            'date' => $day->format('Y-m-d'), 'availability' => $v]);
    }

    public function test_前日と翌日を見る(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        $a = PersonFactory::new()->staff()->create(['office' => '東京']);
        $b = PersonFactory::new()->staff()->create(['office' => '東京']);
        $c = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->pref($a, $day->copy()->subDay(), 'NG');
        $this->pref($b, $day->copy()->addDay(), '希望休');
        $this->pref($c, $day->copy()->subDay(), '未定');
        $mk = fn (string $lodging) => ProjectFactory::new()->create(['start_date' => $day->format('Y-m-d'), 'lodging' => $lodging, 'office' => '東京']);

        $pre = $mk('前泊有');
        $post = $mk('後泊あり');
        $both = $mk('前後泊あり');
        $none = $mk('無');
        $map = StayDayCheck::map([$pre, $post, $both, $none]);

        $prev = $day->copy()->subDay()->format('n/j');
        $this->assertSame([$a->id => '前日'.$prev.'が×（前泊）'], $map[$pre->id]);
        $this->assertSame([$b->id => '翌日'.$day->copy()->addDay()->format('n/j').'が×（後泊）'], $map[$post->id]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_keys($map[$both->id]), '前後泊は両方');
        $this->assertArrayNotHasKey($none->id, $map, '宿泊なしは見ない');
        $this->assertArrayNotHasKey($c->id, $map[$pre->id], '未定（△）は札にしない');
    }

    /** 月まとめ自動アサインは、前泊の前日が×の人を選ばない。 */
    public function test_月まとめ自動アサインは選ばない(): void
    {
        $day = Carbon::today()->startOfMonth()->addDays(10);
        ProjectFactory::new()->published()->create(['start_date' => $day->format('Y-m-d'), 'required_count' => 1,
            'office' => '東京', 'lodging' => '前泊有']);
        $ng = PersonFactory::new()->staff()->create(['name' => '前日だめ子', 'office' => '東京']);
        $this->pref($ng, $day, '稼働可');
        $this->pref($ng, $day->copy()->subDay(), 'NG');

        $this->assertSame(0, (new MonthAutoAssign($day->format('Y-m')))->plan()['totals']['added']);

        // 見張りの見張り：宿泊なしに変えれば同じ人が入る（＝0件の理由は前日の×だけ）。
        \App\Models\Project::query()->update(['lodging' => '無']);
        $this->assertSame(1, (new MonthAutoAssign($day->format('Y-m')))->plan()['totals']['added']);
    }

    /** 案件別アサイン：候補には残し、「自動候補から除外」に理由を出す。 */
    public function test_案件別アサインは札つきで残す(): void
    {
        $day = Carbon::today()->addDays(5);
        $p = ProjectFactory::new()->published()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京', 'lodging' => '前泊有']);
        $s = PersonFactory::new()->staff()->create(['name' => '前日だめ子', 'office' => '東京']);
        $this->pref($s, $day, '稼働可');
        $this->pref($s, $day->copy()->subDay(), 'NG');
        $emp = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($emp)->get('/project-assign?project='.urlencode($p->id))->assertOk()
            ->assertSee('前日だめ子')->assertSee('自動候補から除外：前日'.$day->copy()->subDay()->format('n/j').'が×（前泊）');
    }

    /** 日別ボード：カードに stayNg が渡り、JSの詰め替え・自動アサイン・札にも書いてある。 */
    public function test_日別ボードに渡る(): void
    {
        $day = Carbon::today()->addDays(3);
        $p = ProjectFactory::new()->published()->create(['start_date' => $day->format('Y-m-d'), 'office' => '東京', 'lodging' => '後泊あり']);
        $s = PersonFactory::new()->staff()->create(['office' => '東京']);
        $this->pref($s, $day->copy()->addDay(), 'NG');
        $emp = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $res = $this->actingAsPerson($emp)->get('/assign')->assertOk();
        $card = collect($res->original->getData()['boardCases'])->firstWhere('id', $p->id);
        $this->assertSame([$s->id => '翌日'.$day->copy()->addDay()->format('n/j').'が×（後泊）'], (array) $card['stayNg']);

        $html = $res->getContent();
        $this->assertStringContainsString('stayNg:(c.stayNg || {})', $html, '詰め替え漏れの見張り');
        $this->assertStringContainsString('{ why.stay++; return false; }', $html, '⚡自動アサインで選ばない');
        $this->assertStringContainsString('${stayTag}', $html, '希望者に札');
    }
}
