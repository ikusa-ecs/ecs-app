<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 巻き取ってもらった案件に「自拠点からも人を出す」（2026-09-30 baba要望）。
 * 【babaの言葉】「案件を他拠点に巻き取ってもらうけど、その拠点で足りなくて自拠点でも人を出す場合は？」
 *
 * ⚠ 印を付けたときだけ、登録した拠点の**日別ボード**にも出す。スタッフ公開ボードには出さない
 *   （公開・非公開は運営する拠点＝引き取った側が決める）。3つ目の拠点には出さない。
 */
class TakeoverOriginHelpsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $office)
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => $office, 'must_onboard' => false]);
    }

    private function takenOver(bool $helps)
    {
        $p = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '名古屋', 'kind' => '巻き取り', 'origin_helps' => $helps]);

        return $p;
    }

    private function boardIds($who, string $office): array
    {
        return collect($this->actingAsPerson($who)->get('/assign?office='.urlencode($office))->assertOk()->viewData('boardCases'))
            ->pluck('id')->all();
    }

    public function test_印が無ければ登録した拠点の日別ボードに出ない(): void
    {
        $p = $this->takenOver(false);

        $this->assertNotContains($p->id, $this->boardIds($this->manager('東京'), '東京'));
        $this->assertContains($p->id, $this->boardIds($this->manager('名古屋'), '名古屋'));
    }

    public function test_印を付けると登録した拠点の日別ボードにも出る(): void
    {
        $p = $this->takenOver(true);
        $tokyo = $this->manager('東京');

        $this->assertContains($p->id, $this->boardIds($tokyo, '東京'));
        $this->assertContains($p->id, $this->boardIds($this->manager('名古屋'), '名古屋'), '引き取った拠点には今までどおり出る');

        // 札にも「東京からも応援」と出る。
        $card = collect($this->actingAsPerson($tokyo)->get('/assign?office=東京')->viewData('boardCases'))->firstWhere('id', $p->id);
        $this->assertStringContainsString('東京からも応援', collect($card['shareTags'])->pluck('label')->implode(' '));

        // スタッフ公開ボードにも出る（2026-09-30 から公開は拠点ごと＝東京は東京のスタッフにだけ公開できる）。
        $pub = collect($this->actingAsPerson($tokyo)->get('/assign-publish?office=東京')->assertOk()->viewData('cases'));
        $this->assertTrue($pub->contains('id', $p->id));
    }

    /** 公開は拠点ごと：名古屋で公開しても、東京のスタッフには出ない（2026-09-30 baba「公開ボードは拠点ごとに」）。 */
    public function test_公開は拠点ごと(): void
    {
        $p = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'staff_published' => false, 'is_recruiting' => true, 'status' => '調整中',
        ]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '名古屋', 'kind' => '巻き取り', 'origin_helps' => true]);
        $tokyoStaff = PersonFactory::new()->staff()->create(['office' => '東京', 'must_onboard' => false]);
        $nagoyaStaff = PersonFactory::new()->staff()->create(['office' => '名古屋', 'must_onboard' => false]);
        $sees = fn ($who) => collect($this->actingAsPerson($who)->get('/staff-portal')->assertOk()->viewData('recruitJobs'))
            ->contains(fn ($j) => ($j['id'] ?? null) === $p->id);

        // 名古屋の公開ボードで公開 → 名古屋のスタッフだけ。
        $this->actingAsPerson($this->manager('名古屋'))
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => true, 'office' => '名古屋'])->assertOk();
        $this->assertTrue($sees($nagoyaStaff));
        $this->assertFalse($sees($tokyoStaff), '名古屋で公開したのに東京のスタッフにも出ている');

        // 東京の公開ボードでも公開 → 両方。東京だけ非公開にしても名古屋はそのまま。
        $tokyo = $this->manager('東京');
        $this->actingAsPerson($tokyo)
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => true, 'office' => '東京'])->assertOk();
        $this->assertTrue($sees($tokyoStaff));
        $this->actingAsPerson($tokyo)
            ->postJson('/assign-publish/set', ['ids' => [$p->id], 'publish' => false, 'office' => '東京'])->assertOk();
        $this->assertFalse($sees($tokyoStaff));
        $this->assertTrue($sees($nagoyaStaff), '東京で非公開にしたら名古屋からも消えた');
        $this->assertTrue((bool) $p->fresh()->staff_published, 'どこかで公開中なら staff_published は true のまま');
    }

    /** これまでに公開した案件（拠点の指定なし）は、今までどおり関わる全拠点に出る。 */
    public function test_これまでの公開は全拠点のまま(): void
    {
        $p = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'), 'is_recruiting' => true, 'status' => '調整中',
        ]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '名古屋', 'kind' => 'ヘルプ']);
        $nagoyaStaff = PersonFactory::new()->staff()->create(['office' => '名古屋', 'must_onboard' => false]);

        $this->assertNull($p->fresh()->published_offices);
        $this->assertTrue(collect($this->actingAsPerson($nagoyaStaff)->get('/staff-portal')->viewData('recruitJobs'))
            ->contains(fn ($j) => ($j['id'] ?? null) === $p->id));
    }

    public function test_付け外しは登録した拠点だけ(): void
    {
        $p = $this->takenOver(false);

        $this->actingAsPerson($this->manager('名古屋'))
            ->postJson('/projects/origin-helps', ['id' => $p->id, 'on' => true])->assertStatus(403);
        $this->assertFalse((bool) ProjectShare::first()->origin_helps);

        $this->actingAsPerson($this->manager('東京'))
            ->postJson('/projects/origin-helps', ['id' => $p->id, 'on' => true])->assertOk();
        $this->assertTrue((bool) ProjectShare::first()->origin_helps);
    }
}
