<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\ScheduleMark;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * コンテンツ名に書かれた「リハ」から日程種別を読み取り、本番につなぐ（2026-09-15 baba要望）。
 *
 * 【babaの言葉】「リハはコンテンツ名にリハって書いてあることが多い」
 *   実例＝`綱引き大会(リハ)` / `鷹狩りリハーサル`
 *
 * 【直る前に起きていたこと】
 *  ① リハが「本番」として入る（回数にも数えてしまう）
 *  ② 本番と紐づかない（別々の案件に見える）
 *  ③ **コンテンツ台帳に「綱引き大会(リハ)」という新しいコンテンツが増える**
 *
 * ⚠ **1件に決められないものは紐づけない。**勘で正しくない本番にぶら下げると誰も気づけない。
 */
class ScheduleMarkImportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** list形式（1案件＝1行）のCSVを作る。 */
    private function csv(array $rows): UploadedFile
    {
        $head = ['案件名', '開催日', '顧客名', '運営人数'];
        $lines = [implode(',', $head)];
        foreach ($rows as $r) {
            $lines[] = implode(',', $r);
        }

        return UploadedFile::fake()->createWithContent(
            'assign.csv', implode("\n", $lines)."\n"
        );
    }

    // ── 印の読み取り ────────────────────────────────────────────

    public function test_かっこつきのリハを読み取る(): void
    {
        $m = ScheduleMark::detect('綱引き大会(リハ)');

        $this->assertSame('リハ日', $m['kind']);
        $this->assertSame('綱引き大会', $m['clean'], 'かっこごと外れていない');
    }

    public function test_うしろに付いたリハーサルを読み取る(): void
    {
        $m = ScheduleMark::detect('鷹狩りリハーサル');

        $this->assertSame('リハ日', $m['kind']);
        $this->assertSame('鷹狩り', $m['clean']);
    }

    public function test_全角かっこや角かっこでも読み取る(): void
    {
        $this->assertSame('水合戦', ScheduleMark::detect('水合戦（リハ）')['clean']);
        $this->assertSame('水合戦', ScheduleMark::detect('水合戦【リハ】')['clean']);
        $this->assertSame('水合戦', ScheduleMark::detect('水合戦 リハ')['clean']);
    }

    public function test_予備日と前日設営も読み取る(): void
    {
        $this->assertSame('予備日', ScheduleMark::detect('運動会(予備日)')['kind']);
        $this->assertSame('前日設営', ScheduleMark::detect('運動会(前日設営)')['kind']);
    }

    /** ⚠ 印が無いふつうの案件は、そのまま（何も変えない）。 */
    public function test_印が無ければ何もしない(): void
    {
        $this->assertNull(ScheduleMark::detect('綱引き大会'));
        $this->assertNull(ScheduleMark::detect('水合戦'));
    }

    /** ⚠ 「リハビリ」を「リハ」と読み違えない。 */
    public function test_リハビリは印ではない(): void
    {
        $this->assertNull(ScheduleMark::detect('リハビリ体験会'));
    }

    /** ⚠ 印を外すと何も残らない名前は、コンテンツが分からないので印にしない。 */
    public function test_リハだけの名前は印にしない(): void
    {
        $this->assertNull(ScheduleMark::detect('リハ'));
        $this->assertNull(ScheduleMark::detect('(リハ)'));
    }

    // ── 取り込み ────────────────────────────────────────────────

    public function test_取り込むとリハ日になり本番につながる(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(30);

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                // ⚠ わざとリハを先に置く（本番より先に並んでいても紐づくこと）。
                ['綱引き大会(リハ)', $day->copy()->subDay()->format('Y-m-d'), '株式会社テスト', '5'],
                ['綱引き大会', $day->format('Y-m-d'), '株式会社テスト', '10'],
            ]),
            'office' => '東京',
        ])->assertRedirect('/past-import');

        $honban = Project::where('date_type', '本番')->first();
        $riha = Project::where('date_type', 'リハ日')->first();

        $this->assertNotNull($riha, 'リハ日として入っていない');
        $this->assertSame('綱引き大会', $riha->project_name, '印が名前に残っている');
        $this->assertSame($honban->id, $riha->parent_project_id, '本番につながっていない');
    }

    /** ⚠ コンテンツ台帳に「綱引き大会(リハ)」を増やさない（これが起きていた）。 */
    public function test_コンテンツ台帳に印つきの名前を増やさない(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(30);

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                ['綱引き大会(リハ)', $day->format('Y-m-d'), '株式会社テスト', '5'],
            ]),
            'office' => '東京',
        ])->assertRedirect('/past-import');

        $names = Content::pluck('content_name')->all();
        $this->assertNotContains('綱引き大会(リハ)', $names, 'リハつきの名前が台帳に増えている');
        $this->assertContains('綱引き大会', $names);
    }

    /** ⚠ 本番が見つからないときは紐づけず、一覧で知らせる（勘でつながない）。 */
    public function test_本番が無ければつながず知らせる(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(30);

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                ['鷹狩りリハーサル', $day->format('Y-m-d'), '株式会社テスト', '5'],
            ]),
            'office' => '東京',
        ])->assertRedirect('/past-import');

        $riha = Project::where('date_type', 'リハ日')->first();
        $this->assertNotNull($riha);
        $this->assertNull($riha->parent_project_id, '本番が無いのにつないでいる');

        $res->assertSessionHas('status', fn ($m) => str_contains($m, 'つないでいません')
            && str_contains($m, '鷹狩り'));
    }

    /** ⚠ 同じくらい近い本番が2つあるときは決めない（どちらが正しいか分からない）。 */
    public function test_本番が複数あって決められなければつながない(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(30);

        // リハの前後1日に、同じ名前・同じお客様の本番が1つずつ＝距離が同じ。
        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                ['水合戦', $day->copy()->subDay()->format('Y-m-d'), '株式会社テスト', '10'],
                ['水合戦', $day->copy()->addDay()->format('Y-m-d'), '株式会社テスト', '10'],
                ['水合戦(リハ)', $day->format('Y-m-d'), '株式会社テスト', '5'],
            ]),
            'office' => '東京',
        ])->assertRedirect('/past-import');

        $riha = Project::where('date_type', 'リハ日')->first();
        $this->assertNotNull($riha);
        $this->assertNull($riha->parent_project_id, '決められないのにつないでいる');
    }

    /** お客様が違う本番にはつながない。 */
    public function test_お客様が違えばつながない(): void
    {
        $me = $this->manager();
        $day = Carbon::today()->addDays(30);

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                ['水合戦', $day->format('Y-m-d'), '別の会社', '10'],
                ['水合戦(リハ)', $day->copy()->subDay()->format('Y-m-d'), '株式会社テスト', '5'],
            ]),
            'office' => '東京',
        ])->assertRedirect('/past-import');

        $riha = Project::where('date_type', 'リハ日')->first();
        $this->assertNull($riha->parent_project_id);
    }
}
