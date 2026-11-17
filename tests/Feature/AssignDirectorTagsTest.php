<?php

namespace Tests\Feature;

use App\Models\ProjectShare;
use App\Support\TakeoverTag;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * D決めの画面：案件に「巻き取り」「ヨミ」の札を出す（2026-11-17 小沼さん
 * 「D未定のままだとどきっとする。他拠点に巻き取ってもらう案件・Bヨミの案件だと分かるように」）。
 *
 * ・ヨミは「確定」と空のときは出さない（アサイン表と同じ決め）。
 * ・巻き取りの文字の正本＝App\Support\TakeoverTag（アサイン表と同じ言い方）。
 */
class AssignDirectorTagsTest extends TestCase
{
    use RefreshDatabase;

    /** 画面に渡している案件（window.ECS_DIR_CASES）を取り出す。 */
    private function cases(string $html): array
    {
        $this->assertSame(1, preg_match('/window\.ECS_DIR_CASES\s*=\s*(\[.*?\]);/s', $html, $m));

        return collect(json_decode($m[1], true))->keyBy('id')->all();
    }

    public function test_巻き取りとヨミが案件に付く(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $manager = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $take = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'yomi' => 'Bヨミ']);
        ProjectShare::create(['project_id' => $take->id, 'office' => '大阪', 'kind' => '巻き取り']);
        $plain = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'yomi' => '確定']);
        $help = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'yomi' => null]);
        ProjectShare::create(['project_id' => $help->id, 'office' => '大阪', 'kind' => 'ヘルプ']);

        // 登録した拠点（東京）で見る＝大阪に巻き取ってもらう案件は出さない（Dを決めないので）。
        $html = $this->actingAsPerson($manager)->get('/assign-director?office=東京')->assertOk()->getContent();
        $cases = $this->cases($html);
        $this->assertArrayNotHasKey($take->id, $cases);
        // 確定は出さない／ヘルプは巻き取りではない（ヘルプの案件は登録した拠点に出したまま）。
        $this->assertSame('', $cases[$plain->id]['yomi']);
        $this->assertSame('', $cases[$plain->id]['takeoverTag']);
        $this->assertSame('', $cases[$help->id]['takeoverTag']);

        // 巻き取った拠点（大阪）で見る＝出る。札は「東京から巻き取り」とヨミ。
        $cases = $this->cases($this->actingAsPerson($manager)->get('/assign-director?office=大阪')->assertOk()->getContent());
        $this->assertSame('東京から巻き取り', $cases[$take->id]['takeoverTag']);
        $this->assertSame('Bヨミ', $cases[$take->id]['yomi']);
        // 札を作る部品が画面に入っている。
        $this->assertStringContainsString('function caseTags', $html);
    }

    public function test_巻き取りの文字は見ている拠点で言い方が変わる(): void
    {
        $shares = [['office' => '大阪', 'kind' => '巻き取り']];
        $this->assertSame('大阪が巻き取り', TakeoverTag::of($shares, '東京', '東京'));
        $this->assertSame('東京から巻き取り', TakeoverTag::of($shares, '東京', '大阪'));
        $this->assertSame('', TakeoverTag::of([['office' => '大阪', 'kind' => 'ヘルプ']], '東京', '東京'));
        $this->assertSame('', TakeoverTag::of([], '東京', '東京'));
    }
}
