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
        // 下見では「名前で見つけた案件」としてどの案件かが出る（2026-09-30 baba「変わりますなのにIDでつながってない？」）。
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('ヒラメキ・クエスト', 'テスト様')])->assertOk();
        $link = $this->actingAsPerson($me)->postJson('/past-import/preview', ['sync' => SheetSync::firstOrFail()->id])
            ->assertOk()->json('rows.0.link');
        $this->assertSame('name', $link['by']);
        $this->assertStringContainsString('P-2026-0001', $link['project']);

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

    /** 「🆕 新しい案件」の行に、同じ日のECSの案件が選べる候補として出る（2026-09-30 baba要望）。 */
    public function test_同じ日のECSの案件が候補に出る(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0101', 'project_name' => '別の書き方の案件', 'client' => '板橋区', 'date_type' => '前日設営']);
        $this->ecsProject(['id' => 'P-2026-0102', 'project_name' => '違う日', 'client' => 'X', 'start_date' => '2026-09-02']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('まったく違う名前', '別の顧客')])->assertOk();

        $row = $this->actingAsPerson($me)->postJson('/past-import/preview', ['sync' => SheetSync::firstOrFail()->id])
            ->assertOk()->json('rows.0');

        $this->assertSame('new', $row['diff']['kind']);
        $this->assertSame(['P-2026-0101'], array_column($row['sameDay'], 'id'), '同じ日の案件だけ出る');
        $this->assertStringContainsString('前日設営', $row['sameDay'][0]['label']);

        $page = $this->actingAsPerson($me)->get('/past-import')->assertOk()->getContent();
        $this->assertStringContainsString('function pjPickSameDay', $page);
        // 少しずつ取り込むための「まとめてチェック」（2026-09-30 baba要望）。
        $this->assertStringContainsString("pjBulk('none')", $page);
        $this->assertStringContainsString('function pjBulkSetup', $page);
    }

    /**
     * 取り込んだあと、GASがIDを書いただけでは「シートが変わった」にしない＋手でつないだ行は開き直しても新規に戻らない
     * （2026-09-30 baba「全部取り込んだのにまた差分が出てる」）。
     */
    public function test_取り込んだあとIDを書いただけでは差分にならない(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0042', 'project_name' => 'ECSでの名前', 'content_names' => ['ECSでの名前'], 'client' => '別の書き方']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $rows = $this->rows('シートの名前', '金沢');
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $sync = SheetSync::firstOrFail();

        // 名前が違うので手でつないで取り込む。
        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['linkId' => 'P-2026-0042']]),
        ])->assertRedirect('/past-import');
        $this->assertFalse($sync->fresh()->needsAttention());

        // ① 開き直しても（GASがIDを書く前でも）新しい案件には戻らない。
        $row = $this->actingAsPerson($me)->postJson('/past-import/preview', ['sync' => $sync->id])->assertOk()->json('rows.0');
        $this->assertNotSame('new', $row['diff']['kind'], '手でつないだのに、開き直すと新しい案件に戻っている');

        // ② 次の朝＝GASがIDを書いたシートが届く。IDが増えただけなので「確認が要る」に戻らない。
        $rows[99][0] = 'ECS案件ID（自動・編集しないでください）';
        $rows[99][self::A] = 'P-2026-0042';
        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $this->assertFalse($res->json('changed'));
        $this->assertFalse($sync->fresh()->needsAttention(), 'IDを書いただけで受信箱が「確認が要る」に戻った');
        $this->assertSame('P-2026-0042', $sync->fresh()->rows[99][self::A], 'ECSが持つ中身にIDが入っていない');
    }

    /**
     * 100行目が空の行を手でつないだら、次の送信でそのIDを書かせる。チェック0件で取り込んでも記録は消えない
     * （2026-09-30 baba「IDでつなげるにしてるのにスプシに書かれてない」）。
     */
    public function test_手でつないだIDは空の100行目にも書かれチェック0件でも消えない(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0042', 'project_name' => 'ECSでの名前', 'content_names' => ['ECSでの名前'], 'client' => '別']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $rows = $this->rows('シートの名前', '金沢');
        $send = fn () => $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $send();
        $sync = SheetSync::firstOrFail();

        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['linkId' => 'P-2026-0042']]),
        ])->assertRedirect('/past-import');

        // チェックを全部外して取り込み直す（「確認が要る」の印を消すだけ）。記録は残ること。
        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['skip' => true]]),
        ])->assertRedirect('/past-import');
        $this->assertSame('P-2026-0042', (string) ($sync->fresh()->project_ids[self::A] ?? ''), 'チェック0件の取込で記録が消えた');

        // 次の送信（シートの100行目はまだ空）→ 手でつないだIDを書かせる。
        $this->assertSame('P-2026-0042', $send()->json('projectIds.'.self::A), '手でつないだIDを書かせていない');
    }

    /** 受信箱の一覧に「差分の件数」の欄があり、下見と同じ入口で数える（2026-09-30 baba要望）。 */
    public function test_受信箱に差分の件数を出す(): void
    {
        $me = $this->manager();
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京',
            'rows' => $this->rows('チャンバラ', '金沢')])->assertOk();
        $sync = SheetSync::firstOrFail();

        $html = $this->actingAsPerson($me)->get('/sheet-inbox?office=東京')->assertOk()->getContent();
        $this->assertStringContainsString('差分（取り込むと変わる件数）', $html);
        $this->assertStringContainsString('data-sync="'.$sync->id.'"', $html);
        $this->assertStringContainsString("fetch('/past-import/preview'", $html);

        // 数え方＝下見そのもの。まだECSに無いので「新規1件」。
        $rows = $this->actingAsPerson($me)->post('/past-import/preview', ['sync' => $sync->id, 'mode' => '過去', 'office' => '東京'],
            ['Accept' => 'application/json'])->assertOk()->json('rows');
        $this->assertSame('new', $rows[0]['diff']['kind']);
    }

    /**
     * 運営人数が空で、となりの「形式」に「募集」とあるとき、運営人数を「募集」と読まない
     * （2026-09-30 baba「12/26 株式会社リアル・コアで運営人数が募集になってる」）。
     */
    public function test_運営人数が空で形式が募集でも運営人数にしない(): void
    {
        $rows = $this->rows('チャンバラ', 'リアル・コア');
        $rows[8] = array_fill(0, count($rows[8]), '');
        $rows[8][self::A] = '運営人数 / 形式';
        $rows[8][self::A + 6] = '募集';   // 運営人数の欄は空・形式の欄に「募集」

        $case = \App\Support\MonthlySheetReader::read($rows)['cases'][0];

        $this->assertSame('', $case['fields']['運営人数'] ?? '', '「募集」を運営人数として読んでいる');
        $this->assertSame('募集', $case['fields']['形式'] ?? '');
    }

    /**
     * 「ロゴ [ ] カメ [ ] 記事 [ ] 動画 [ ]」の行で、ロゴが空でも見出しの「カメ」をロゴにしない
     * （2026-10-08 baba「シート『カメ』は何も埋まってないだけ」）。カメラの値はカメラに入る。
     */
    public function test_ロゴが空でもカメをロゴにしない(): void
    {
        $rows = $this->rows('チャンバラ', 'ロゴ確認');
        $rows[8] = array_fill(0, count($rows[8]), '');
        $rows[8][self::A] = 'ロゴ';
        $rows[8][self::A + 2] = 'カメ';
        $rows[8][self::A + 3] = 'OK';
        $rows[8][self::A + 4] = '記事';
        $rows[8][self::A + 6] = '動画';

        $fields = \App\Support\MonthlySheetReader::read($rows)['cases'][0]['fields'];

        $this->assertSame('', $fields['ロゴ'] ?? '', '見出しの「カメ」をロゴとして読んでいる');
        $this->assertSame('OK', $fields['カメラ'] ?? '');
        $this->assertSame('', $fields['記事'] ?? '');
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
        // ⚠ 前回の記録を使うのは「日付とコンテンツが両方合う」ときだけ（同じ日のブロックの入れ替えに備える）。
        $this->ecsProject(['id' => 'P-2026-0315', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'], 'client' => '別の書き方']);

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

        // ⚠ 受信箱が受け取った時点で、ずれたIDはECSが持つ中身から消える（GASに消させるのと同じ・2026-09-30）。
        $this->assertSame('', $sync->rows[99][self::A], 'ずれたIDがECSの持つ中身に残っている');
        $pre = $this->actingAsPerson($me)->postJson('/past-import/preview', ['sync' => $sync->id])->assertOk()->json('rows.0');
        $this->assertSame('new', $pre['diff']['kind'], 'ずれたIDの案件に上書きしようとしている');

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

    /**
     * 同じ日のブロックを並べ替えても、正しいIDを上書きしない（2026-09-30 baba「順番入れかえたらおかしくなる？」）。
     * IDはブロックと一緒に動く。前回の「列 → ID」の記録は古い位置のまま＝記録を先に使ってはいけない。
     */
    public function test_同じ日のブロックを入れ替えても正しいIDを上書きしない(): void
    {
        $this->ecsProject(['id' => 'P-2026-0081', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'], 'client' => 'A']);
        $this->ecsProject(['id' => 'P-2026-0082', 'project_name' => '謎パ', 'content_names' => ['謎パ'], 'client' => 'B']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        // このブロック（13列目）はいまチャンバラで、100行目にも正しく P-2026-0081。記録は古い位置の P-2026-0082。
        $rows = $this->rows('チャンバラ', 'A', 'P-2026-0081');
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        SheetSync::firstOrFail()->forceFill(['project_ids' => [(string) self::A => 'P-2026-0082']])->save();

        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();

        $this->assertNull($res->json('projectIds.'.self::A), '正しいIDを古い記録で書き換えようとしている');
    }

    /** 下見で別の案件につなぎ直したら、次の朝シートの古いIDを書き換える。 */
    public function test_手でつなぎ直したIDは次の朝シートに書かれる(): void
    {
        $me = $this->manager();
        $this->ecsProject(['id' => 'P-2026-0091', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'], 'client' => 'A']);
        $this->ecsProject(['id' => 'P-2026-0092', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'], 'client' => 'A2', 'start_time' => '13:00']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $rows = $this->rows('チャンバラ', 'A', 'P-2026-0091');
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $sync = SheetSync::firstOrFail();

        $this->actingAsPerson($me)->post('/past-import', [
            'sync' => $sync->id, 'edits' => json_encode(['0' => ['linkId' => 'P-2026-0092']]),
        ])->assertRedirect('/past-import');

        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $this->assertSame('P-2026-0092', $res->json('projectIds.'.self::A));
    }

    /**
     * 2ブロック並んだ表（左＝13列目・右＝23列目）。各ブロック [日程の文字, コンテンツ, 顧客, 100行目のID]。
     */
    private function twoBlocks(array $left, array $right): array
    {
        $b = self::BLOCK_WIDTH;
        $rows = [];
        foreach ([[self::A, $left], [self::A + $b, $right]] as [$col, $blk]) {
            $one = $this->rows($blk[1], $blk[2], $blk[3] ?? null);
            foreach ($one as $r => $line) {
                $rows[$r] ??= array_fill(0, self::A + $b * 2 + 2, '');
                for ($i = 0; $i < $b; $i++) {
                    $rows[$r][$col + $i] = $line[self::A + $i] ?? '';
                }
            }
            $rows[3][$col + 3] = $blk[0];   // 日程
        }

        return array_values($rows);
    }

    /**
     * セールスがブロックをコピーして作り、100行目のIDまで写った（2026-09-30 baba）。
     * ⚠ コピーを元より左に置いても、コピーが元の案件を上書きしないこと。
     */
    public function test_コピーで同じIDが2か所にあっても元の案件を上書きしない(): void
    {
        $me = $this->manager();
        $orig = $this->ecsProject(['id' => 'P-2026-0200', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'],
            'client' => '元の顧客', 'start_date' => '2026-09-01']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        // 左＝コピー（9/8・同じコンテンツ・別の顧客）／右＝元（9/1）。どちらも100行目は P-2026-0200。
        $rows = $this->twoBlocks(['9月8日(火)', 'チャンバラ', 'コピーの顧客', 'P-2026-0200'],
            ['9月1日(火)', 'チャンバラ', '元の顧客', 'P-2026-0200']);
        $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $sync = SheetSync::firstOrFail();

        // ⚠ 受信箱が受け取った時点で、コピーのほうのIDはECSが持つ中身から消える（元のほうは残る）。
        $this->assertSame('', $sync->rows[99][self::A], 'コピーのIDが残っている');
        $this->assertSame('P-2026-0200', $sync->rows[99][self::A + self::BLOCK_WIDTH], '元のIDが消えた');

        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])->assertRedirect('/past-import');

        $orig->refresh();
        $this->assertSame('2026-09-01', $orig->start_date->format('Y-m-d'), 'コピーの日程で元の案件を上書きしている');
        $this->assertSame(2, Project::count(), 'コピーは別の案件として入る');

        // 次の朝：（GASが前の返事どおりコピーのIDを消したシートが届く）→ コピーのブロックに新しい案件のIDを書かせる。
        $rows[99][self::A] = '';
        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();
        $newId = Project::where('id', '!=', 'P-2026-0200')->value('id');
        $this->assertSame($newId, $res->json('projectIds.'.self::A));
        $this->assertNull($res->json('projectIds.'.(self::A + self::BLOCK_WIDTH)), '元のブロックのIDは触らない');
    }

    /** 取り込む前でも、元が決まればコピーのIDは毎朝の書き戻しで消す。 */
    public function test_毎朝の書き戻しでコピーのIDを消す(): void
    {
        $this->ecsProject(['id' => 'P-2026-0200', 'project_name' => 'チャンバラ', 'content_names' => ['チャンバラ'],
            'client' => '元の顧客', 'start_date' => '2026-09-01']);
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $rows = $this->twoBlocks(['9月8日(火)', 'チャンバラ', 'コピーの顧客', 'P-2026-0200'],
            ['9月1日(火)', 'チャンバラ', '元の顧客', 'P-2026-0200']);

        $res = $this->postJson('/sheet-sync', ['token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows])->assertOk();

        $this->assertSame('', $res->json('projectIds.'.self::A), 'コピーのIDを消させていない');
        $this->assertNull($res->json('projectIds.'.(self::A + self::BLOCK_WIDTH)));
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
