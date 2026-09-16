<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「ほかの拠点に巻き取られた案件」を、登録拠点の**日別ボードとスタッフ公開ボード**から外す
 * （2026-09-16 baba要望「巻き取りにしてるのに日別ボードに出てくる。
 *   巻き取られた側は日別ボードには出ないでほしい」）。
 *
 * 言葉の意味（ここを取り違えると逆のことをする）
 * ・**ヘルプ**＝案件は登録拠点のまま。人だけ出す → 登録拠点にも**出したまま**にする。
 * ・**巻き取り**＝相手の拠点が運営する → 登録拠点は人を入れないので**外す**。
 *
 * ⚠ 外すのはこの2画面だけ。**案件一覧・集計・収支からは外さない**
 *   （外すと「解除」の入口にたどり着けなくなり、登録拠点の実績も消える）。
 *   正本＝App\Support\OfficeScope::hideTakenOver。
 */
class TakenOverProjectHiddenTest extends TestCase
{
    use RefreshDatabase;

    private function emp(string $office)
    {
        return PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'manager', 'office' => $office,
            'must_onboard' => false, 'active' => true,
        ]);
    }

    /** 東京で登録して、大阪に巻き取ってもらった案件を作る。 */
    private function takenOver(string $kind = '巻き取り')
    {
        $p = ProjectFactory::new()->create([
            'office' => '東京',
            'status' => '調整中',
            'start_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
        ]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '大阪', 'kind' => $kind]);

        return $p;
    }

    /** 巻き取られた側（東京）の日別ボードには出ない。 */
    public function test_day_board_hides_a_project_taken_over_by_another_office(): void
    {
        $p = $this->takenOver();

        $ids = collect($this->actingAsPerson($this->emp('東京'))->get('/assign?office=東京')
            ->assertOk()->original->getData()['boardCases'])->pluck('id')->all();

        $this->assertNotContains($p->id, $ids, '巻き取られた案件は登録拠点のボードに出さない');
    }

    /** 巻き取った側（大阪）の日別ボードには出る。 */
    public function test_day_board_shows_it_for_the_office_that_took_it_over(): void
    {
        $p = $this->takenOver();

        $ids = collect($this->actingAsPerson($this->emp('大阪'))->get('/assign?office=大阪')
            ->assertOk()->original->getData()['boardCases'])->pluck('id')->all();

        $this->assertContains($p->id, $ids, '巻き取った拠点のボードには出す');
    }

    /** ヘルプは「人だけ出す」なので、登録拠点のボードに出したままにする。 */
    public function test_day_board_keeps_a_help_share_on_the_owner_office(): void
    {
        $p = $this->takenOver('ヘルプ');

        $ids = collect($this->actingAsPerson($this->emp('東京'))->get('/assign?office=東京')
            ->assertOk()->original->getData()['boardCases'])->pluck('id')->all();

        $this->assertContains($p->id, $ids, 'ヘルプは運営が変わらないので消さない');
    }

    /** 全拠点で見ているときは消さない（どこの案件も見える画面なので）。 */
    public function test_day_board_keeps_it_when_looking_at_all_offices(): void
    {
        $p = $this->takenOver();

        $ids = collect($this->actingAsPerson($this->emp('東京'))->get('/assign?office=all')
            ->assertOk()->original->getData()['boardCases'])->pluck('id')->all();

        $this->assertContains($p->id, $ids, '全拠点表示では消さない');
    }

    /** スタッフ公開ボード：巻き取られた側には出さない（自分のスタッフに募集を出さないため）。 */
    public function test_publish_board_hides_a_project_taken_over_by_another_office(): void
    {
        $p = $this->takenOver();

        $ids = collect($this->actingAsPerson($this->emp('東京'))->get('/assign-publish?office=東京')
            ->assertOk()->original->getData()['cases'])->pluck('id')->all();

        $this->assertNotContains($p->id, $ids);
    }

    /** スタッフ公開ボード：巻き取った側には出る（そこが募集を出す）。 */
    public function test_publish_board_shows_it_for_the_office_that_took_it_over(): void
    {
        $p = $this->takenOver();

        $ids = collect($this->actingAsPerson($this->emp('大阪'))->get('/assign-publish?office=大阪')
            ->assertOk()->original->getData()['cases'])->pluck('id')->all();

        $this->assertContains($p->id, $ids);
    }

    /**
     * 案件一覧には残す。⚠ ここを一緒に消すと「解除」が押せなくなる。
     * 消したくなったときは必ず baba に確かめてから（2026-09-16 決定）。
     */
    public function test_project_list_still_shows_it_so_the_takeover_can_be_released(): void
    {
        $p = $this->takenOver();

        $html = $this->actingAsPerson($this->emp('東京'))->get('/projects?office=東京')
            ->assertOk()->getContent();

        $this->assertStringContainsString($p->id, $html, '案件一覧には残す（解除の入口）');
    }
}
