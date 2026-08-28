<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 月シート取込の「巻き取り／ヘルプ」を、拠点間の関わりとして記録する（2026-08-28 baba要望）。
 *
 * 【これまで】
 * シートの日程の上にある「巻き取り」「ヘルプ」は、備考に【他拠点から巻き取り】と
 * 文字を足すだけだった。⚠ シートに**どの拠点からかが書かれていない**ため、
 * 勘で拠点を決めると拠点別の集計が狂うので、あえて印にしていなかった。
 *
 * 【これから】
 * 取込の画面で「相手の拠点」を選べるようにして、選ばれたときだけ印を付ける。
 * ⚠ 選ばなければ今までどおり（備考の文字だけ）。勘では決めない。
 */
class ImportCrossOfficeShareTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'role' => 'employee',
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /**
     * 月シートの見本（1案件＝横1ブロック）。日程の上の印に「巻き取り」を入れる。
     * ⚠ 形は PastProjectImportTest::monthlyCsv と同じ（読み取りの正本＝MonthlySheetReader）。
     */
    private function sheet(): UploadedFile
    {
        $blank = fn () => array_fill(0, 24, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };

        $rows = [];
        $rows[] = $put($blank(), [13 => '1']);                       // ブロック番号
        $rows[] = $put($blank(), [13 => '巻き取り']);                 // 日程の上の印
        $rows[] = $put($blank(), [13 => 'イベント東(リアル)']);        // 種別（＝実施形態）
        $rows[] = $put($blank(), [13 => '日程', 16 => '9月13日(日)', 19 => '宿泊', 20 => '無']);
        $rows[] = $put($blank(), [13 => 'コンテンツ', 16 => '水合戦']);
        $rows[] = $put($blank(), [13 => '案件規模', 16 => '小型', 18 => '営業担当', 20 => '馬場 智之']);
        $rows[] = $put($blank(), [13 => '顧客名（代理店名）', 16 => 'テスト株式会社']);
        $rows[] = $put($blank(), [13 => '集合/解散/拘束時間', 16 => '9:00', 18 => '17:00', 20 => '8:00']);
        $rows[] = $put($blank(), [13 => '人数 / チーム数', 16 => '50名', 19 => '10チーム']);
        $rows[] = $put($blank(), [13 => '運営人数 / 形式', 16 => '5名', 19 => '確定']);
        $rows[] = $put($blank(), [13 => '備考', 16 => 'テスト']);
        $rows[] = $put($blank(), [13 => 'NO', 14 => '名前', 17 => 'P', 18 => '巡回', 19 => '備考']);

        $line = fn (array $r) => implode(',', array_map('strval', $r));

        return UploadedFile::fake()->createWithContent(
            '東京アサイン表 - 202609.csv', implode("\n", array_map($line, $rows))."\n"
        );
    }

    private function upload(Person $me, array $extra = [])
    {
        return $this->actingAsPerson($me)->post('/past-import', array_merge([
            'csv' => $this->sheet(),
            'period' => '2026-09',
            'office' => '東京',
        ], $extra));
    }

    /** 相手の拠点を選ぶと、拠点間の関わりとして記録される。 */
    public function test_share_is_recorded_when_the_office_is_chosen(): void
    {
        $me = $this->manager();

        $this->upload($me, ['share_office' => '福岡'])->assertRedirect('/past-import');

        $p = Project::first();
        $this->assertNotNull($p, '案件が取り込めていない');

        $share = ProjectShare::where('project_id', $p->id)->first();
        $this->assertNotNull($share, '巻き取りの印が付いていない');
        $this->assertSame('福岡', $share->office);
        $this->assertSame('巻き取り', $share->kind);
    }

    /** ⚠ 相手の拠点を選ばなければ、これまでどおり印は付けない（勘で拠点を決めない）。 */
    public function test_nothing_is_recorded_without_a_chosen_office(): void
    {
        $me = $this->manager();

        $this->upload($me)->assertRedirect('/past-import');

        $this->assertNotNull(Project::first(), '案件は取り込めているはず');
        $this->assertSame(0, ProjectShare::count(), '拠点を選んでいないのに印が付いてしまった');
    }

    /** 備考の文字はこれまでどおり残る（人が読んで分かるように）。 */
    public function test_the_note_mark_is_kept(): void
    {
        $me = $this->manager();

        $this->upload($me, ['share_office' => '福岡']);

        $this->assertStringContainsString('巻き取り', (string) Project::first()->note);
    }

    /** ⚠ 取込先と同じ拠点は受け付けない（自分に頼む印は作らない）。 */
    public function test_same_office_is_ignored(): void
    {
        $me = $this->manager();

        $this->upload($me, ['office' => '東京', 'share_office' => '東京']);

        $this->assertSame(0, ProjectShare::count());
    }

    /** ⚠ 拠点マスタに無い名前は受け付けない。 */
    public function test_unknown_office_is_ignored(): void
    {
        $me = $this->manager();

        $this->upload($me, ['share_office' => '沖縄']);

        $this->assertSame(0, ProjectShare::count());
    }

    /** 取り込み直しても印は増えない（案件×拠点で1つ）。 */
    public function test_reimport_does_not_duplicate(): void
    {
        $me = $this->manager();

        $this->upload($me, ['share_office' => '福岡']);
        $this->upload($me, ['share_office' => '福岡']);

        $this->assertSame(1, ProjectShare::count());
    }

    /** 取込画面に「相手の拠点」の欄がある。 */
    public function test_import_screen_has_the_field(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->get('/past-import')
            ->assertOk()
            ->assertSee('name="share_office"', false)
            ->assertSee('「巻き取り・ヘルプ」の相手の拠点', false);
    }
}
