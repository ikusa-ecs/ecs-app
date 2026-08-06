<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\ProjectImportColumns;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * アサイン表（東京アサイン表）のCSVをそのまま取り込めるか（2026-08-06 baba要望）。
 *
 * ねらい：アサイン表の「1行1案件」のシート（例 202601_list）をCSVで保存して、
 *   **列を並べ替えずに** そのまま `/project-import` へ入れられるようにする。
 *   列名の読み替え表＝App\Support\ProjectImportColumns。
 *
 * アサイン表の実際の見出し（テスト用xlsxから確認）：
 *   No. / 募集 / 種別 / 日程 / 宿泊 / コンテンツ / 案件規模 / 営業担当 / オンラインツール /
 *   配信種別 / 顧客名(代理店名) / 運営場所 / 複数開催 / 集合 / 解散 / 拘束 / 入場 / 開始 / 終了 /
 *   顧客担当名 / 人数 / チーム数 / 運営人数 / 形式 / 運営方式 / 担当
 */
class AssignSheetCsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** アサイン表の見出しのままのCSVで案件が登録される（列の並べ替え不要）。 */
    public function test_assign_sheet_headers_are_imported(): void
    {
        $user = PersonFactory::new()->create();

        $header = 'No.,募集,種別,日程,宿泊,コンテンツ,案件規模,営業担当,オンラインツール,配信種別,'
            . '顧客名(代理店名),運営場所,複数開催,集合,解散,拘束,入場,開始,終了,顧客担当名,人数,チーム数,運営人数,形式,運営方式,担当';
        $row = '1,募集する,イベント東(リアル),2026/9/20,前泊有,水合戦,大型,小沼,,なし,'
            . '〇〇株式会社,千葉,あり,8:00,17:00,9,9:30,10:00,16:00,田中様,120,8,16,,通常,田中 健一';

        $file = UploadedFile::fake()->createWithContent('assign_sheet.csv', $header . "\n" . $row . "\n");

        $this->actingAsPerson($user)->post('/project-import', ['csv' => $file])
            ->assertRedirect('/projects');

        $p = Project::where('project_name', '水合戦')->first();
        $this->assertNotNull($p, 'アサイン表の見出しで取り込めていない');

        // 日付・時刻・人数がそれぞれ正しい項目に入る
        $this->assertSame('2026-09-20', $p->start_date->format('Y-m-d'));
        $this->assertSame('8:00', $p->start_time);
        $this->assertSame('17:00', $p->end_time);
        $this->assertSame('9:30', $p->event_enter_time);
        $this->assertSame('10:00', $p->event_start_time);
        $this->assertSame('16:00', $p->event_end_time);
        $this->assertSame(120, $p->guest_count);
        $this->assertSame(8, $p->team_count);
        $this->assertSame(16, $p->required_count);

        // 名前が違うだけの列も正しく入る
        $this->assertSame('〇〇株式会社', $p->client);        // 顧客名(代理店名) → クライアント
        $this->assertSame('イベント東(リアル)', $p->format);   // 種別 → 実施形態
        $this->assertSame('大型', $p->scale);
        $this->assertSame('千葉', $p->operation_place);       // 運営場所
        $this->assertSame('前泊有', $p->lodging);             // 宿泊
        $this->assertSame(['小沼'], $p->sales_owners);        // 営業担当
        $this->assertTrue((bool) $p->is_multi);               // 複数開催 → 複数案件
        $this->assertTrue((bool) $p->is_recruiting);          // 募集 → スタッフ募集

        // ⚠ 「担当」（ディレクター）は取り込まない（取込時点ではDが決まっていないため）
        $this->assertNull($p->director_id);
    }

    /** 対応する項目が無い列は無視し、「取り込まなかった列」として知らせる。 */
    public function test_unmapped_columns_are_reported(): void
    {
        $user = PersonFactory::new()->create();

        $file = UploadedFile::fake()->createWithContent(
            'x.csv',
            "日程,コンテンツ,運営人数,オンラインツール,拘束,謎の列\n2026-09-21,テスト案件,5,Zoom,9,あ\n"
        );

        $res = $this->actingAsPerson($user)->post('/project-import', ['csv' => $file]);

        $status = session('status');
        $this->assertStringContainsString('取り込まなかった列', $status);
        $this->assertStringContainsString('謎の列', $status);
        $this->assertSame(1, Project::count());
    }

    /** Excelの日付・時刻（数字のまま書き出された場合）も読める。 */
    public function test_excel_serial_date_and_time_are_normalized(): void
    {
        // 46285 ＝ 2026-09-20（Excelのシリアル値）／0.3333… ＝ 8:00
        $this->assertSame('2026-09-20', ProjectImportColumns::normalizeDate('46285'));
        // 実物のアサイン表に入っていた値（46208）＝2026-07-05
        $this->assertSame('2026-07-05', ProjectImportColumns::normalizeDate('46208'));
        $this->assertSame('8:00', ProjectImportColumns::normalizeTime('0.3333333333333333'));
        $this->assertSame('17:00', ProjectImportColumns::normalizeTime('0.7083333333333334'));

        // よくある書き方もまとめて読める
        $this->assertSame('2026-09-20', ProjectImportColumns::normalizeDate('2026/9/20'));
        $this->assertSame('2026-09-20', ProjectImportColumns::normalizeDate('2026年9月20日'));
        $this->assertSame('2026-09-20', ProjectImportColumns::normalizeDate('2026-09-20(日)'));
        $this->assertSame('9:00', ProjectImportColumns::normalizeTime('9時'));
    }

    /** 年が無い日付（9/20）は勘で補わず、エラーにして人に直してもらう。 */
    public function test_date_without_year_is_rejected_with_a_clear_message(): void
    {
        $user = PersonFactory::new()->create();
        $this->assertSame('', ProjectImportColumns::normalizeDate('9/20'));

        $file = UploadedFile::fake()->createWithContent('x.csv', "日程,コンテンツ,運営人数\n9/20,年なし案件,5\n");

        $this->actingAsPerson($user)->post('/project-import', ['csv' => $file]);

        $this->assertSame(0, Project::count());
        $this->assertStringContainsString('年から入れてください', session('status'));
    }

    /** ECSのテンプレートの列名も今までどおり読める（両方受け付ける）。 */
    public function test_ecs_template_headers_still_work(): void
    {
        $user = PersonFactory::new()->create();

        $file = UploadedFile::fake()->createWithContent(
            'x.csv',
            "案件名,開催日,運営人数,クライアント,集合時間\nテンプレ案件,2026-09-22,7,テスト商事,08:30\n"
        );

        $this->actingAsPerson($user)->post('/project-import', ['csv' => $file]);

        $p = Project::where('project_name', 'テンプレ案件')->first();
        $this->assertNotNull($p);
        $this->assertSame('テスト商事', $p->client);
        $this->assertSame('8:30', $p->start_time);
    }
}
