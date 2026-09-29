<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\SheetSync;
use App\Support\ImportContents;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサイン表の取込（受信箱・差分取込）の「同じ案件か」と「コンテンツ台帳」（2026-09-29 baba要望）。
 *
 * 【babaの言葉】
 *  「差分取り込みで案件登録してるはずなのに新しい案件になっちゃう」
 *  「コンテンツは量産しないでほしい。複数コンテンツ重なってるのに1つで新しく作成されてるものがある」
 *
 * 【守りたいこと】
 *  ① ECSで先に登録した案件（台帳の名前・「株式会社」つきの顧客名）と、シートの書き方が少し違っても同じ案件になる
 *  ② シートの100行目にECSの案件IDがあれば、それで同じ案件と決める
 *  ③ 「A・B」のように複数書いてあれば、区切って台帳につなぐ。取込では台帳を増やさない
 */
class SheetImportMatchTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef';

    private const BLOCK_WIDTH = 10;

    private const A = 13;   // 1件目のブロックの左端の列

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 月ごとのアサイン表（1案件＝横1ブロック・9/1 の1件）。100行目まで行を作る。 */
    private function rows(string $content, string $client, ?string $ecsId = null): array
    {
        $cols = self::A + self::BLOCK_WIDTH + 2;
        $blank = fn () => array_fill(0, $cols, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };
        $a = self::A;

        $rows = [];
        $rows[] = $put($blank(), [$a => '1']);
        $rows[] = $blank();
        $rows[] = $blank();
        $rows[] = $put($blank(), [$a => '日程', $a + 3 => '9月1日(火)', $a + 6 => '宿泊', $a + 7 => '無']);
        $rows[] = $put($blank(), [$a => 'コンテンツ', $a + 3 => $content]);
        $rows[] = $put($blank(), [$a => '案件規模', $a + 3 => '小型']);
        $rows[] = $put($blank(), [$a => '顧客名（代理店名）', $a + 3 => $client]);
        $rows[] = $put($blank(), [$a => '集合/解散/拘束時間', $a + 3 => '9:00', $a + 5 => '17:00']);
        $rows[] = $put($blank(), [$a => '運営人数 / 形式', $a + 3 => '5名']);
        $rows[] = $put($blank(), [$a => 'NO', $a + 1 => '名前', $a + 4 => 'P']);
        while (count($rows) < 100) {
            $rows[] = $blank();
        }
        if ($ecsId !== null) {
            $rows[99] = $put($rows[99], [0 => 'ECS案件ID（自動・編集しないでください）', $a => $ecsId]);
        }

        return $rows;
    }

    private function import(Person $me, array $rows): void
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows,
        ])->assertOk();

        $this->actingAsPerson($me)->post('/past-import', ['sync' => SheetSync::firstOrFail()->id])
            ->assertRedirect('/past-import');
    }

    private function ecsProject(array $attrs): Project
    {
        return Project::create($attrs + [
            'start_date' => '2026-09-01', 'start_time' => '9:00', 'office' => '東京',
            'status' => '未着手', 'required_count' => 5,
        ]);
    }

    public function test_ECSで登録した案件は書き方が違っても同じ案件になる(): void
    {
        $me = $this->manager();
        Content::create(['id' => 'CT-900', 'content_name' => 'ヒラメキクエスト', 'active' => true]);
        $p = $this->ecsProject([
            'id' => 'P-2026-0001', 'project_name' => 'ヒラメキクエスト', 'content_ids' => ['CT-900'],
            'content_names' => ['ヒラメキクエスト'], 'client' => '株式会社テスト',
        ]);

        // シートは「ヒラメキ・クエスト」「テスト様」（書き方だけ違う）。
        $this->import($me, $this->rows('ヒラメキ・クエスト', 'テスト様'));

        $this->assertSame(1, Project::count(), '新しい案件が増えている');
        $this->assertSame('株式会社テスト', $p->fresh()->client, 'ECSの顧客名の書き方は残す');
        $this->assertSame(1, Content::count(), 'コンテンツ台帳が増えている');
    }

    public function test_100行目のECSのIDで同じ案件と決める(): void
    {
        $me = $this->manager();
        $p = $this->ecsProject([
            'id' => 'P-2026-0042', 'project_name' => '昔の名前', 'client' => 'まったく別の顧客',
        ]);

        $this->import($me, $this->rows('新しい名前', '別の書き方の顧客', 'P-2026-0042'));

        $this->assertSame(1, Project::count(), 'IDがあるのに新しい案件が増えている');
        $this->assertSame('P-2026-0042', Project::first()->id);
    }

    public function test_IDの日付が違えば信じない(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0042', 'project_name' => '別の日の案件', 'client' => 'X', 'start_date' => '2026-09-20']);

        $this->import($me, $this->rows('新しい名前', 'Y', 'P-2026-0042'));

        $this->assertSame(2, Project::count(), '日付の違う案件に上書きしている');
    }

    public function test_複数コンテンツは区切って台帳につなぎ台帳を増やさない(): void
    {
        $me = $this->manager();
        Content::create(['id' => 'CT-900', 'content_name' => '謎パ', 'active' => true]);
        Content::create(['id' => 'CT-901', 'content_name' => '格付けバトル', 'active' => true]);

        $this->import($me, $this->rows('謎パ・格付けバトル', '株式会社サンプル'));

        $p = Project::firstOrFail();
        $this->assertSame(['CT-900', 'CT-901'], $p->content_ids);
        $this->assertSame('謎パ・格付けバトル', $p->project_name);
        $this->assertSame(2, Content::count(), '「謎パ・格付けバトル」が台帳に増えている');
    }

    public function test_台帳に無い名前は単発で残し台帳には足さない(): void
    {
        $r = (new ImportContents)->resolve('まだ無いコンテンツ');
        $this->assertSame([], $r['ids']);
        $this->assertSame(['まだ無いコンテンツ'], $r['names']);
        $this->assertSame(0, Content::count());

        // 1つだけ台帳にある＋1つは無い → あるほうだけつなぎ、無いほうは名前だけ残す。
        Content::create(['id' => 'CT-900', 'content_name' => '謎パ', 'active' => true]);
        $r = (new ImportContents)->resolve('謎パ＋まだ無い');
        $this->assertSame(['CT-900'], $r['ids']);
        $this->assertSame(['謎パ', 'まだ無い'], $r['names']);
        $this->assertSame(['まだ無い'], $r['unknown']);
    }

    public function test_顧客名の比べ方(): void
    {
        $this->assertSame(ImportContents::clientKey('株式会社テスト'), ImportContents::clientKey('テスト 様'));
        $this->assertSame(ImportContents::clientKey('（株）テスト'), ImportContents::clientKey('ﾃｽﾄ'));
        $this->assertNotSame(ImportContents::clientKey('テスト'), ImportContents::clientKey('サンプル'));
    }
}
