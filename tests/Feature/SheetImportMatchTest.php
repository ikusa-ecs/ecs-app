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

    /** IDがあれば日付が違っても同じ案件（baba「IDで管理してるなら同じものって紐づけたい」）。日程とアサインも新しい日に移る。⚠ 日付もコンテンツも違うIDは使わない（test_ずれたIDは使わない）。 */
    public function test_IDがあれば日付が違っても同じ案件(): void
    {
        $me = $this->manager();
        // 日程変更＝日付は違うが、コンテンツは同じ（＝同じ案件と分かる）。
        $p = $this->ecsProject(['id' => 'P-2026-0042', 'project_name' => '新しい名前', 'content_names' => ['新しい名前'],
            'client' => 'X', 'start_date' => '2026-09-20']);
        $staff = PersonFactory::new()->create(['office' => '東京']);
        \App\Models\Assignment::create(['project_id' => $p->id, 'staff_id' => $staff->id, 'date' => '2026-09-20', 'role' => 'OP', 'status' => '確定']);

        $this->import($me, $this->rows('新しい名前', 'Y', 'P-2026-0042'));

        $this->assertSame(1, Project::count(), 'IDがあるのに新しい案件が増えている');
        $this->assertSame('2026-09-01', $p->fresh()->start_date->format('Y-m-d'), '日程がシートに合わせて直っていない');
        $this->assertSame('2026-09-01', \App\Models\Assignment::first()->date->format('Y-m-d'), 'アサインが古い日に残っている');
    }

    /** 下見で手でECSの案件IDを入れたら、その案件に上書きする（baba「IDで紐づけを手動でも」）。 */
    public function test_手で入れたIDでつなぐ(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0042', 'project_name' => '名古屋巻き取りの案件', 'client' => 'まったく別']);

        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('チャンバラ・運動会', '金沢（VIPROGY）')])->assertOk();
        $sync = SheetSync::firstOrFail();

        // 下見にも「手でつなぐ」が出る。
        $pre = $this->actingAsPerson($me)->postJson('/past-import/preview', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['linkId' => 'p-2026-0042']]),
        ])->assertOk()->json('rows.0');
        $this->assertSame('manual', $pre['link']['by']);
        $this->assertNotSame('new', $pre['diff']['kind']);

        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['linkId' => 'P-2026-0042']]),
        ])->assertRedirect('/past-import');

        $this->assertSame(1, Project::count(), '手でつないだのに新しい案件が増えた');
        $this->assertSame(['13' => 'P-2026-0042'], array_map('strval', $sync->fresh()->project_ids), 'シートへ書き戻すIDになっていない');
    }

    /** 手で入れたIDが見つからなければ、新しく作らずに止める（打ち間違いで二重にしない）。 */
    public function test_手で入れたIDが無ければ作らない(): void
    {
        $me = $this->manager();
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('チャンバラ', '顧客')])->assertOk();

        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => SheetSync::firstOrFail()->id, 'edits' => json_encode(['0' => ['linkId' => 'P-2026-7777']]),
        ])->assertRedirect('/past-import');

        $this->assertSame(0, Project::count());
    }

    /**
     * 100行目がまだ空でも、ECSが前回残した「列 → ID」の対応表でつなぐ（2026-09-29 P-2026-0315 の件）。
     * IDがシートに書かれるのは受け取ったあと＝その朝に届いた中身にはまだ入っていないため。
     */
    public function test_100行目が空でも前回の対応表でつなぐ(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0315', 'project_name' => '名古屋巻き取りの案件', 'client' => '別の書き方']);

        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('チャンバラ・運動会', '金沢（VIPROGY）')])->assertOk();
        $sync = SheetSync::firstOrFail();
        $sync->forceFill(['project_ids' => [(string) self::A => 'P-2026-0315']])->save();

        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])->assertRedirect('/past-import');

        $this->assertSame(1, Project::count(), '前回の対応表があるのに新しい案件が増えた');
    }

    /** ECSで「前日設営」と登録した案件を、シートの取込で「本番」に戻さない（2026-09-29 11/2 板橋区の件）。 */
    public function test_前日設営を本番に戻さない(): void
    {
        $me = $this->manager();
        $p = $this->ecsProject(['id' => 'P-2026-0050', 'project_name' => '運動会', 'client' => '板橋区', 'date_type' => '前日設営']);

        // 月ごとのアサイン表には日程種別の欄が無い＝何も書いていない。
        $this->import($me, $this->rows('運動会', '板橋区', 'P-2026-0050'));

        $this->assertSame(1, Project::count());
        $this->assertSame('前日設営', $p->fresh()->date_type, '取込で本番に戻っている');
    }

    /** シートが空欄のところは、ECSで入れた値を消さない（2026-09-29 baba「ECSで登録してるものは？」）。 */
    public function test_シートの空欄でECSの値を消さない(): void
    {
        $me = $this->manager();
        $p = $this->ecsProject([
            'id' => 'P-2026-0060', 'project_name' => '運動会', 'client' => '板橋区',
            'format' => 'リアルロング', 'note' => 'ECSで書いた備考', 'yomi' => 'Aヨミ',
            'prep_script' => true, 'is_recruiting' => false, 'status' => '確定', 'required_count' => 12,
        ]);

        $this->import($me, $this->rows('運動会', '板橋区', 'P-2026-0060'));

        $p->refresh();
        $this->assertSame('リアルロング', $p->format);
        $this->assertSame('ECSで書いた備考', $p->note);
        $this->assertSame('Aヨミ', $p->yomi, '空欄の確度で「確定」に上書きしている');
        $this->assertTrue((bool) $p->prep_script, '準備のチェックが外れている');
        $this->assertSame('確定', $p->status);
        $this->assertSame(5, (int) $p->required_count, 'シートに書いてある人数（5名）は入る');
    }

    /**
     * 100行目のIDが別の案件を指していたら使わない（2026-09-29 baba「IDがばらばら」）。
     * ブロックを足す・並べ替えると、IDが隣のブロックに付いていることがあるため。
     */
    public function test_ずれたIDは使わない(): void
    {
        $me = $this->manager();
        // 別の日・別のコンテンツの案件のIDが、このブロックの100行目に付いている。
        $other = $this->ecsProject(['id' => 'P-2026-0070', 'project_name' => 'マグロ解体ショー',
            'content_names' => ['マグロ解体ショー'], 'client' => 'コカ', 'start_date' => '2026-09-15']);

        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('チャンバラ', '金沢', 'P-2026-0070')])->assertOk();
        $sync = SheetSync::firstOrFail();

        $pre = $this->actingAsPerson($me)->postJson('/past-import/preview', ['sync' => $sync->id])->assertOk()->json('rows.0');
        $this->assertStringContainsString('別の案件', $pre['idMismatch']);

        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])->assertRedirect('/past-import');

        $this->assertSame('マグロ解体ショー', $other->fresh()->project_name, '別の案件を上書きしている');
        $this->assertSame(2, Project::count());
    }

    /** 毎朝の返事：いまのブロックと合わないIDは返さず、シートにずれたIDがあれば空にさせる。 */
    public function test_毎朝の書き戻しはずれたIDを消す(): void
    {
        $this->ecsProject(['id' => 'P-2026-0070', 'project_name' => 'マグロ解体ショー',
            'content_names' => ['マグロ解体ショー'], 'client' => 'コカ', 'start_date' => '2026-09-15']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $rows = $this->rows('チャンバラ', '金沢', 'P-2026-0070');

        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        SheetSync::firstOrFail()->forceFill(['project_ids' => [(string) self::A => 'P-2026-0070']])->save();

        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();

        $this->assertSame('', $res->json('projectIds.'.self::A), 'ずれたIDを消させていない');
    }

    /** 消した案件のIDが残っていたら、ふつうに名前で探す（落ちない）。 */
    public function test_消した案件のIDなら名前で探す(): void
    {
        $me = $this->manager();

        $this->import($me, $this->rows('新しい名前', 'Y', 'P-2026-0999'));

        $this->assertSame(1, Project::count());
        $this->assertNotSame('P-2026-0999', Project::first()->id);
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
