<?php

namespace Tests\Feature;

use App\Http\Controllers\PastProjectImportController;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Support\MonthlySheetReader;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 名古屋のアサイン表を /past-import で安全に取り込む（2026-11-16 小沼さん決定）。
 *
 *  ① 名古屋の表に載っている他拠点（東京・大阪など）の案件は取り込まずに飛ばす。
 *     持ち主の拠点は日程の上の印で見る（「イベント名（リアル）」「イベント阪（ヘルプ）」「イベント大阪」）。
 *     拠点が複数並ぶ印（「イベント阪名福（巻き取り）」）は勝手に決めない＝はじめは取り込まず、人が選ぶ。
 *     ⚠ 東京の表の矢印（「イベント東→他拠点(巻き取り)」）・「イベント東北」は今までどおり。
 *  ② P列が空の人は、右隣の「巡回」列に書かれた役割を読む。
 *     ⚠ 東京の表の巡回の数や印（「3」「巡回」）は役割にしない。P列がある人は P列のまま。
 */
class NagoyaSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return Person::find('E-001') ?? PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '名古屋', 'must_onboard' => false,
        ]);
    }

    private function staff(string $id, string $name): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => 'staff', 'permission' => 'staff',
            'office' => '名古屋', 'must_onboard' => false,
        ]);
    }

    /**
     * 名古屋の形の月シート（1案件＝横10列のブロック）を、ブロックをいくつも並べて作る。
     *
     * @param  list<array{mark:string, date:string, content:string, client?:string,
     *                    people: list<array{0:string, 1:string, 2?:string}>}>  $blocks  people＝[名前, P, 巡回]
     */
    private function sheet(array $blocks, string $filename = '名古屋アサイン表2025 - 202510.csv'): UploadedFile
    {
        $width = 13 + 10 * count($blocks) + 1;
        $rows = array_fill(0, 12 + max(array_map(fn ($b) => count($b['people']), $blocks)), array_fill(0, $width, ''));
        foreach ($blocks as $k => $b) {
            $c = 13 + 10 * $k;
            $rows[0][$c] = (string) ($k + 1);
            $rows[1][$c] = 'LINE登録済';
            $rows[2][$c] = $b['mark'];
            $rows[3][$c] = '日程';
            $rows[3][$c + 3] = $b['date'];
            $rows[4][$c] = 'コンテンツ';
            $rows[4][$c + 3] = $b['content'];
            $rows[5][$c] = '顧客名（代理店名）';
            $rows[5][$c + 3] = $b['client'] ?? '株式会社なごや';
            $rows[6][$c] = '集合/解散/拘束時間';
            $rows[6][$c + 3] = '8:00';
            $rows[7][$c] = '運営人数 / 形式';
            $rows[7][$c + 3] = '5名';
            // 名古屋の見出し＝NO／名前／（空）／（空）／P／巡回
            $rows[9][$c] = 'NO';
            $rows[9][$c + 1] = '名前';
            $rows[9][$c + 4] = 'P';
            $rows[9][$c + 5] = '巡回';
            foreach ($b['people'] as $i => $p) {
                $rows[10 + $i][$c] = (string) ($i + 1);
                $rows[10 + $i][$c + 1] = $p[0];
                $rows[10 + $i][$c + 4] = $p[1];
                $rows[10 + $i][$c + 5] = $p[2] ?? '';
            }
        }
        $line = fn (array $r) => implode(',', array_map(
            fn ($v) => str_contains((string) $v, ',') ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v, $r));

        return UploadedFile::fake()->createWithContent($filename, implode("\n", array_map($line, $rows))."\n");
    }

    private function block(string $mark, string $date, string $content, array $people = [['鈴木 彩', 'MC']], string $client = '株式会社なごや'): array
    {
        return ['mark' => $mark, 'date' => $date, 'content' => $content, 'client' => $client, 'people' => $people];
    }

    // ───────── ① 他拠点の案件を飛ばす ─────────

    public function test_名古屋式の印で他拠点の案件は取り込まず同じ拠点の案件は入る(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'office' => '名古屋',
            'csv' => $this->sheet([
                $this->block('イベント名（リアル）', '10/3(金)', 'クイズ大会'),
                $this->block('イベント阪（ヘルプ）', '10/4(土)', 'チャンバラ'),
                $this->block('イベント東（ヘルプのみ）', '10/19(日)', '五稜郭チャンバラ'),
                $this->block('イベント東京', '10/20(月)', 'ワルドリ'),          // 2024年の書き方（カッコなし）
                $this->block('イベント名古屋（リアル）', '10/21(火)', '運動会'),  // 2024年の書き方
            ]),
        ])->assertRedirect('/past-import');

        $this->assertEqualsCanonicalizing(['クイズ大会', '運動会'], Project::pluck('project_name')->all(),
            '持ち主が名古屋の案件だけ入る');
        $this->assertSame(['名古屋'], Project::distinct()->pluck('office')->all());
        $msg = (string) $res->getSession()->get('status');
        $this->assertStringContainsString('他の拠点の案件3件は取り込みませんでした', $msg, '黙って捨てない');
        $this->assertStringContainsString('チャンバラ・大阪の案件', $msg);
        $this->assertStringContainsString('ワルドリ・東京の案件', $msg);
    }

    public function test_下見では他拠点の案件に理由が付き_イベント大阪の印は取り込まなかった項目に出ない(): void
    {
        $json = $this->actingAsPerson($this->manager())->post('/past-import/preview', [
            'office' => '名古屋',
            'csv' => $this->sheet([
                $this->block('イベント名（リアル）', '10/3(金)', 'クイズ大会'),
                $this->block('イベント大阪', '10/4(土)', '電脳都市'),
            ]),
        ])->assertOk()->json();

        $this->assertNull($json['rows'][0]['otherOffice']);
        $this->assertSame('other', $json['rows'][1]['otherOffice']['kind']);
        $this->assertSame('他の拠点の案件なので取り込みません（大阪の案件）', $json['rows'][1]['otherOffice']['note']);
        $this->assertNotContains('イベント大阪', $json['unmapped'], '持ち主の拠点として読めた印は「取り込まなかった項目」に出さない');
    }

    /**
     * いちばん避けたい事故：東京で取込済みの案件に当たって、東京側のアサインを消して入れ直す。
     */
    public function test_東京で取込済みの案件に当たっても東京のアサインは消えない(): void
    {
        $tokyo = PersonFactory::new()->create(['id' => 'S-100', 'name' => '東京 太郎', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false]);
        $this->staff('S-001', '鈴木 彩');
        $p = Project::create(['id' => 'P-2025-0001', 'project_name' => '五稜郭チャンバラ', 'content_names' => ['五稜郭チャンバラ'],
            'client' => '株式会社なごや', 'start_date' => '2025-10-19', 'start_time' => '8:00', 'office' => '東京',
            'status' => '確定', 'required_count' => 5]);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $tokyo->id, 'date' => '2025-10-19', 'role' => 'MC', 'status' => '確定']);

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'office' => '名古屋',
            'csv' => $this->sheet([$this->block('イベント東（ヘルプのみ）', '10/19(日)', '五稜郭チャンバラ', [['鈴木 彩', 'MC']])]),
        ])->assertRedirect('/past-import');

        $this->assertSame(1, Project::count(), '二重登録しない');
        $this->assertSame(['S-100'], Assignment::where('project_id', $p->id)->pluck('staff_id')->all(), '東京のアサインはそのまま');
    }

    /**
     * 拠点が複数並ぶ印は勝手に決めない。はじめは取り込まず、人がチェックを入れた（takeOther）ときだけ入れる。
     */
    public function test_拠点が複数並ぶ印は人が選ぶまで取り込まない(): void
    {
        $this->staff('S-001', '鈴木 彩');
        $make = fn () => $this->sheet([$this->block('イベント阪名福（巻き取り）', '10/24(金)', 'リモ謎')]);

        $json = $this->actingAsPerson($this->manager())->post('/past-import/preview', ['office' => '名古屋', 'csv' => $make()])
            ->assertOk()->json();
        $this->assertSame('ambiguous', $json['rows'][0]['otherOffice']['kind']);
        $this->assertSame(['大阪', '名古屋', '福岡'], $json['rows'][0]['otherOffice']['owners']);
        $this->assertTrue($json['rows'][0]['skip'], 'はじめは取り込まない');

        // そのまま取り込む → 入らない（結果に出る）
        $res = $this->actingAsPerson($this->manager())->post('/past-import', ['office' => '名古屋', 'csv' => $make()]);
        $this->assertSame(0, Project::count());
        $this->assertStringContainsString('どの拠点か決められない＝大阪・名古屋・福岡', (string) $res->getSession()->get('status'));

        // チェックを入れた → 入る
        $this->actingAsPerson($this->manager())->post('/past-import', ['office' => '名古屋', 'csv' => $make(),
            'edits' => json_encode(['0' => ['takeOther' => true]])])->assertRedirect('/past-import');
        $this->assertSame(['リモ謎'], Project::pluck('project_name')->all());
        $this->assertSame('名古屋', Project::first()->office);
    }

    public function test_候補に取込先が入っていなければはっきり他拠点(): void
    {
        $this->assertSame('other', PastProjectImportController::otherOffice('大阪・名古屋・福岡', '札幌')['kind']);
        // 東京として取り込むときは飛ばさない（東京の表の「阪名福（巻き取り）」は東京の案件・今までどおり）
        $this->assertNull(PastProjectImportController::otherOffice('大阪・名古屋・福岡', '東京'));
        $this->assertNull(PastProjectImportController::otherOffice('大阪', '東京'));
        $this->assertSame('ambiguous', PastProjectImportController::otherOffice('大阪・名古屋・福岡', '名古屋')['kind']);
        $this->assertNull(PastProjectImportController::otherOffice('名古屋', '名古屋'));
        $this->assertNull(PastProjectImportController::otherOffice('', '名古屋'), '印が無い＝今までどおり入れる');
    }

    public function test_持ち主の拠点の読み方(): void
    {
        $this->assertSame('名古屋', MonthlySheetReader::ownerFromMark('イベント名（リアルロング）'));
        $this->assertSame('名古屋', MonthlySheetReader::ownerFromMark('イベント名古屋（リアル）'));
        $this->assertSame('大阪', MonthlySheetReader::ownerFromMark('イベント阪（ヘルプ）'));
        $this->assertSame('大阪', MonthlySheetReader::ownerFromMark('イベント大阪'));
        $this->assertSame('東京', MonthlySheetReader::ownerFromMark('イベント東(リアル)'));
        $this->assertSame('大阪・名古屋・福岡', MonthlySheetReader::ownerFromMark('イベント阪名福（巻き取り）'));
        // 東京の表の書き方は読まない（今までどおり）
        $this->assertNull(MonthlySheetReader::ownerFromMark('イベント東→他拠点(巻き取り)'));
        $this->assertNull(MonthlySheetReader::ownerFromMark('イベント他拠点→東(巻き取り)'));
        $this->assertNull(MonthlySheetReader::ownerFromMark('イベント他拠点(ヘルプのみ)'));
        $this->assertNull(MonthlySheetReader::ownerFromMark('イベント東北(リアル)'), '東北は読まない＝東京の表で飛ばさない');
        $this->assertNull(MonthlySheetReader::ownerFromMark('追加案件'));
    }

    // ───────── 東京の表は今までどおり ─────────

    public function test_東京の表を東京で取り込むと矢印や東北の印の案件も今までどおり入る(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'office' => '東京',
            'csv' => $this->sheet([
                $this->block('イベント東(リアル)', '9月1日(火)', '会議室'),
                $this->block('イベント東→他拠点(巻き取り)', '9月2日(水)', '謎解き'),
                $this->block('イベント他拠点→東(巻き取り)', '9月3日(木)', 'BBQ'),
                $this->block('イベント東北(リアル)', '9月4日(金)', '縁日'),
                $this->block('イベント他拠点(ヘルプのみ)', '9月5日(土)', '運動会'),
                // 東京の表（2025年）にもある書き方。東京の表では「東京の案件を地方の拠点が巻き取った」の意味。
                $this->block('イベント阪名福（巻き取り）', '9月6日(日)', '防災ヒーロー'),
                $this->block('イベント福', '9月7日(月)', 'チャンバラ'),
            ], '2026年東京アサイン表9月～10月 - 202609.csv'),
        ])->assertRedirect('/past-import');

        $this->assertSame(7, Project::count(), '東京の表は1件も飛ばさない');
        $this->assertStringNotContainsString('他の拠点の案件', (string) $res->getSession()->get('status'));
        // 矢印の読み方（備考）も今までどおり
        $this->assertStringContainsString('他拠点に巻き取り', (string) Project::where('project_name', '謎解き')->value('note'));
        $this->assertStringContainsString('他拠点から巻き取り', (string) Project::where('project_name', 'BBQ')->value('note'));
    }

    // ───────── ② 巡回列の役割 ─────────

    public function test_P列が空なら巡回列の役割の言葉を役割として読む(): void
    {
        foreach (['S-001' => '受付 花子', 'S-002' => '司会 次郎', 'S-003' => '競技 三郎', 'S-004' => 'サブ 四郎', 'S-005' => '空欄 五郎'] as $id => $n) {
            $this->staff($id, $n);
        }

        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'office' => '名古屋',
            'csv' => $this->sheet([$this->block('イベント名（リアル）', '10/11(土)', '運動会', [
                ['受付 花子', '', '受付'],
                ['司会 次郎', '', 'MC'],
                ['競技 三郎', '', '競技'],     // 読み替えられない言葉
                ['サブ 四郎', 'SD', '競技'],   // P列がある人は P列のまま
                ['空欄 五郎', '', ''],
            ])]),
        ])->assertRedirect('/past-import');

        $a = Assignment::pluck('role', 'staff_id')->all();
        $this->assertSame('RP', $a['S-001']);
        $this->assertSame('MC', $a['S-002']);
        $this->assertSame('FC', $a['S-003'], '読めない言葉は今までどおりFC');
        $this->assertSame('競技', Assignment::where('staff_id', 'S-003')->value('note'), '書いてあったとおりを担当メモに残す');
        $this->assertSame('SD', $a['S-004']);
        $this->assertSame('FC', $a['S-005']);
        $this->assertSame(['競技'], $res->getSession()->get('past_unknown_roles'), '読めない言葉は「知らない役割」の知らせに乗る');
    }

    public function test_東京式の巡回の数や印は役割にしない(): void
    {
        foreach (['S-001' => '巡回 一郎', 'S-002' => '巡回 二郎', 'S-003' => '軍師 三郎', 'S-004' => '受付 四郎'] as $id => $n) {
            $this->staff($id, $n);
        }

        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'office' => '東京',
            'csv' => $this->sheet([$this->block('イベント東(リアル)', '9月1日(火)', '会議室', [
                ['巡回 一郎', '', '3'],
                ['巡回 二郎', '', '巡回'],
                ['軍師 三郎', 'FC', '軍師'],   // 東京は P列があり、巡回列は担当メモ＝P列のまま
                ['受付 四郎', 'OP', '受付'],
            ])], '2026年東京アサイン表9月～10月 - 202609.csv'),
        ])->assertRedirect('/past-import');

        $a = Assignment::pluck('role', 'staff_id')->all();
        $this->assertSame(['S-001' => 'FC', 'S-002' => 'FC', 'S-003' => 'FC', 'S-004' => 'OP'],
            array_intersect_key($a, array_flip(['S-001', 'S-002', 'S-003', 'S-004'])));
        $this->assertSame('', (string) Assignment::where('staff_id', 'S-001')->value('note'), '巡回の数は担当メモにも入れない（今までどおり）');
        $this->assertSame([], $res->getSession()->get('past_unknown_roles'));
    }

    public function test_巡回列の1マスの読み方(): void
    {
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('3'));
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('３'));
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('巡3'));
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('巡回'));
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('○'));
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('L'), '2024年の表のサイズ');
        $this->assertSame('', MonthlySheetReader::roleFromPatrolCell('M,24,5'));
        $this->assertSame('受付', MonthlySheetReader::roleFromPatrolCell('受付'));
        $this->assertSame('MC', MonthlySheetReader::roleFromPatrolCell(' MC '));
    }
}
