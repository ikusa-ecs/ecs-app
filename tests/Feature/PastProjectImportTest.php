<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 過去案件のCSV取込（/past-import）。2026-08-24 baba要望。
 *
 * これからの案件の取込（/project-import）との違い：
 *  ・D／MC／OP／スタッフ の列からアサインも「確定」で入る
 *  ・案件は「確定」・スタッフに公開済み（本人が過去の実績として見られる）・募集はしない
 *  ・同じ案件（日程・コンテンツ・顧客名・集合時間が全部同じ）は上書き＝取り込み直せる
 *  ・名簿に無い人・同姓同名の人は入れずに一覧で知らせる（人の取り違えを防ぐ）
 */
class PastProjectImportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(string $id, string $name): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /**
     * 実際のアサイン表（案件取込list.csv）と同じ見出しでCSVを作る。
     * 列を並べ替えずにそのまま入れられることを確かめるため、順番も実物に合わせる。
     */
    private function csv(array $rows): UploadedFile
    {
        $header = 'No.,募集,種別,日程,宿泊,コンテンツ,案件規模,営業担当,オンラインツール,配信種別,'
            .'顧客名(代理店名),運営場所,複数開催,集合,解散,拘束,入場,開始,終了,顧客担当名,'
            .'人数,チーム数,運営人数,形式,運営方式,担当,LINE作成,LINE概要送付,引継,ダブチェ,'
            .'運営シート,シート期日,台本,台本期日,音響,ロゴ,カメラ,記事,動画,会場場所,'
            .'集合形式,お酒,物品担当,ケータリング,移動方法,会場種別,備考,D,MC,OP,スタッフ';
        // カンマを含む値（スタッフ列の「A, B」など）は引用符で囲む＝CSVとして正しい形にする。
        $line = fn (array $r) => implode(',', array_map(
            fn ($v) => str_contains((string) $v, ',')
                ? '"'.str_replace('"', '""', (string) $v).'"'
                : (string) $v,
            $r
        ));
        $body = implode("\n", array_map($line, $rows));

        return UploadedFile::fake()->createWithContent('past.csv', $header."\n".$body."\n");
    }

    /** 1行ぶんの値（51列）。指定した列だけ差し替える。 */
    private function row(array $override = []): array
    {
        $base = array_fill(0, 51, '');
        $base[0] = '1';            // No.
        $base[3] = '2026-01-20';   // 日程
        $base[5] = '水合戦';        // コンテンツ
        $base[10] = '株式会社テスト'; // 顧客名(代理店名)
        $base[13] = '08:00';       // 集合
        $base[22] = '5';           // 運営人数

        foreach ($override as $i => $v) {
            $base[$i] = $v;
        }

        return $base;
    }

    /** 案件が「確定・公開済み・募集しない」で入る。 */
    public function test_imports_past_project_as_confirmed_and_published(): void
    {
        $this->actingAsPerson($this->manager())
            ->post('/past-import', ['csv' => $this->csv([$this->row()])])
            ->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame('確定', $p->status);
        $this->assertTrue((bool) $p->staff_published, 'スタッフに公開済みで入ること');
        $this->assertFalse((bool) $p->is_recruiting, '過去案件は募集しないこと');
        $this->assertSame('2026-01-20', $p->start_date->format('Y-m-d'));
        $this->assertSame('株式会社テスト', $p->client);
        $this->assertSame(5, $p->required_count);
    }

    /** D・MC・OP・スタッフの列からアサインが「確定」で入る。 */
    public function test_creates_confirmed_assignments_from_role_columns(): void
    {
        $me = $this->manager();
        $d = PersonFactory::new()->create([
            'id' => 'E-010', 'name' => '田中 健一', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $mc = $this->staff('S-001', '鈴木 彩');
        $op = $this->staff('S-002', '佐藤 大輔');
        $st1 = $this->staff('S-003', '中村 蓮');
        $st2 = $this->staff('S-004', '山本 萌');

        // D列は空白なしの書き方でも当たること（田中健一）を確かめる。
        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([$this->row([
                47 => '田中健一',            // D
                48 => '鈴木 彩',             // MC
                49 => '佐藤 大輔',           // OP
                50 => '中村 蓮, 山本 萌',    // スタッフ（カンマ区切り）
            ])]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();

        $this->assertSame('D', Assignment::where('project_id', $p->id)->where('staff_id', $d->id)->value('role'));
        $this->assertSame('MC', Assignment::where('project_id', $p->id)->where('staff_id', $mc->id)->value('role'));
        $this->assertSame('OP', Assignment::where('project_id', $p->id)->where('staff_id', $op->id)->value('role'));
        // スタッフ列は役割が書かれていないので FC で入る（baba確定）。
        $this->assertSame('FC', Assignment::where('project_id', $p->id)->where('staff_id', $st1->id)->value('role'));
        $this->assertSame('FC', Assignment::where('project_id', $p->id)->where('staff_id', $st2->id)->value('role'));

        // 全部「確定」で、確定の記録も入っている。
        $this->assertSame(5, Assignment::where('project_id', $p->id)->where('status', '確定')->count());
        $this->assertNotNull(Assignment::where('project_id', $p->id)->first()->confirmed_at);
    }

    /** 名簿に無い人は入れずに、一覧で知らせる。 */
    public function test_unknown_person_is_reported_not_created(): void
    {
        $me = $this->manager();
        $known = $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([$this->row([50 => '鈴木 彩, 名簿にいない人'])]),
        ]);

        $res->assertRedirect('/past-import');
        $this->assertContains('名簿にいない人', session('past_missing'));

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        // 名簿にいる人のアサインだけ入る。
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count());
        $this->assertSame($known->id, Assignment::where('project_id', $p->id)->value('staff_id'));
    }

    /** 同姓同名が2人いる場合は、取り違えを防ぐため入れずに知らせる。 */
    public function test_same_name_twice_is_reported_not_guessed(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田 太郎');
        $this->staff('S-002', '山田 太郎');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([$this->row([50 => '山田 太郎'])]),
        ]);

        $res->assertRedirect('/past-import');
        $this->assertContains('山田 太郎', session('past_ambiguous'));

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame(0, Assignment::where('project_id', $p->id)->count());
    }

    /** 同じ案件（日程・コンテンツ・顧客名・集合時間が同じ）は上書き＝二重にならない。 */
    public function test_same_project_is_updated_not_duplicated(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        for ($i = 0; $i < 2; $i++) {
            $this->actingAsPerson($me)->post('/past-import', [
                'csv' => $this->csv([$this->row([50 => '鈴木 彩'])]),
            ])->assertRedirect('/past-import');
        }

        $this->assertSame(1, Project::where('project_name', '水合戦')->count(), '案件が二重にならないこと');
        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count(), 'アサインも二重にならないこと');
    }

    /** 顧客名が違えば別案件として新しく作る（同じ日・同じコンテンツでも）。 */
    public function test_different_client_becomes_separate_project(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                $this->row([10 => 'A社']),
                $this->row([10 => 'B社']),
            ]),
        ])->assertRedirect('/past-import');

        $this->assertSame(2, Project::where('project_name', '水合戦')->count());
    }

    /** 日程が読めない行はエラーにして、他の行は取り込む。 */
    public function test_bad_date_row_is_skipped_with_message(): void
    {
        $me = $this->manager();

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([
                $this->row([3 => '1/20']),            // 年が無い＝エラー
                $this->row([3 => '2026-02-10']),      // OK
            ]),
        ]);

        $res->assertRedirect('/past-import');
        $this->assertSame(1, Project::count());
        $this->assertStringContainsString('日程が読めません', session('status'));
    }

    /** ECSに対応する項目が無い列は、取り込まなかった列として知らせる。 */
    public function test_unmapped_columns_are_reported(): void
    {
        $this->actingAsPerson($this->manager())
            ->post('/past-import', ['csv' => $this->csv([$this->row()])])
            ->assertRedirect('/past-import');

        // 「拘束」「顧客担当名」などはECSに入れる場所が無い＝一覧に出る。
        $this->assertStringContainsString('取り込まなかった項目', session('status'));
        $this->assertStringContainsString('拘束', session('status'));
    }

    /** Excelで保存した Shift_JIS のCSVでも読める。 */
    public function test_reads_shift_jis_csv(): void
    {
        $header = '日程,コンテンツ,顧客名(代理店名),集合,運営人数,スタッフ';
        $body = '2026-03-05,運動会,シフトJIS商事,09:00,4,';
        $sjis = mb_convert_encoding($header."\n".$body."\n", 'SJIS-win', 'UTF-8');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => UploadedFile::fake()->createWithContent('sjis.csv', $sjis),
        ])->assertRedirect('/past-import');

        $this->assertDatabaseHas('projects', ['project_name' => '運動会', 'client' => 'シフトJIS商事']);
    }

    /** 一般社員は使えない（管理者以上のみ）。 */
    public function test_employee_cannot_use_it(): void
    {
        $employee = PersonFactory::new()->create([
            'id' => 'E-050', 'permission' => 'employee', 'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($employee)->get('/past-import')->assertStatus(403);
        $this->actingAsPerson($employee)
            ->post('/past-import', ['csv' => $this->csv([$this->row()])])
            ->assertStatus(403);
    }

    /**
     * 取込画面が「ファイルを選んだらその場で中身が出る」形になっている。
     *
     * ⚠ 最初は「ボタンを押す」形にしていたため、他の取込画面と見た目が同じなのに
     *   ファイルを選んでも何も起きず、壊れていると誤解された（baba指摘 2026-08-24）。
     */
    public function test_screen_previews_on_file_pick(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $html = $this->actingAsPerson($me)->get('/past-import')->assertOk()->getContent();

        // ファイルを選んだら読み込む仕掛けがある。
        $this->assertStringContainsString("getElementById('pjFile').addEventListener('change'", $html);
        // 下見はサーバーに投げる＝読み取りの決まりが1か所（画面とサーバーで食い違わない）。
        $this->assertStringContainsString("fetch('/past-import/preview'", $html);
        // 確認の表と取り込みボタンがある。
        $this->assertStringContainsString('id="pjResult"', $html);
        $this->assertStringContainsString('id="pjBtn"', $html);
    }

    /**
     * 下見（preview）は、登録せずに「何件入るか・誰が入るか」を返す。
     *
     * ⚠ 画面側にも同じ読み取りを書くと片方だけ直して食い違うため、
     *   読み取りはサーバー1か所にした（2026-08-25）。ここはその約束を守る番人。
     */
    public function test_preview_returns_rows_without_saving(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import/preview', [
            'csv' => $this->csv([
                $this->row([50 => '鈴木 彩, 誰か 知らない']),   // 50＝スタッフ列
            ]),
        ])->assertOk();

        $json = $res->json();
        $this->assertTrue($json['ok']);
        $this->assertFalse($json['isMonthly']);
        $this->assertCount(1, $json['rows']);
        $this->assertSame('2026-01-20', $json['rows'][0]['date']);
        $this->assertSame('水合戦', $json['rows'][0]['name']);
        $this->assertSame([], $json['rows'][0]['errors']);
        // 名簿にいる人だけ数え、いない人は名前を返す（登録してから気づく、を避ける）。
        $this->assertSame(1, $json['rows'][0]['people']);
        $this->assertContains('誰か 知らない', $json['missing']);

        // 下見では1件も登録しない。
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Assignment::count());
    }

    /**
     * 月ごとのアサイン表（1案件＝横1ブロック）のCSVを作る。
     *
     * 実物と同じ形にしてある＝項目名が縦に並び、その右に値。下の方に「NO／名前／P」で人。
     * ⚠ 拠点によって項目の場所が少し違うので、位置は決め打ちにせず項目名で読む決まり。
     *   このテストの列位置も、実物とわざと少しずらしてある（ずれても読めることの確認）。
     *
     * @param  list<array{name:string, role:string}>  $people
     */
    private function monthlyCsv(array $people, string $filename = '東京アサイン表 - 202609.csv',
        string $date = '9月1日(火)', string $content = '会議室'): UploadedFile
    {
        $blank = fn () => array_fill(0, 24, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };

        $rows = [];
        $rows[] = $put($blank(), [13 => '1']);                                  // ブロック番号
        $rows[] = $put($blank(), [13 => '日程', 16 => $date, 19 => '宿泊', 20 => '無']);
        $rows[] = $put($blank(), [13 => 'コンテンツ', 16 => $content]);
        $rows[] = $put($blank(), [13 => '案件規模', 16 => '小型', 18 => '営業担当', 20 => '馬場 智之']);
        $rows[] = $put($blank(), [13 => '顧客名（代理店名）', 16 => '株式会社テスト']);
        $rows[] = $put($blank(), [13 => '集合/解散/拘束時間', 16 => '9:00', 18 => '17:00', 20 => '8:00']);
        $rows[] = $put($blank(), [13 => '人数 / チーム数', 16 => '50名', 19 => '10チーム']);
        $rows[] = $put($blank(), [13 => '運営人数 / 形式', 16 => '5名', 19 => '確定']);
        $rows[] = $put($blank(), [13 => '備考', 16 => 'テスト']);
        $rows[] = $put($blank(), [13 => 'NO', 14 => '名前', 17 => 'P', 18 => '巡回', 19 => '備考']);
        foreach ($people as $i => $person) {
            $rows[] = $put($blank(), [13 => (string) ($i + 1), 14 => $person['name'], 17 => $person['role']]);
        }

        $line = fn (array $r) => implode(',', array_map(
            fn ($v) => str_contains((string) $v, ',')
                ? '"'.str_replace('"', '""', (string) $v).'"'
                : (string) $v,
            $r
        ));

        return UploadedFile::fake()->createWithContent(
            $filename, implode("\n", array_map($line, $rows))."\n"
        );
    }

    /**
     * 月ごとのアサイン表（1案件＝横1ブロック）も、そのまま取り込める。
     * ⚠ 他拠点は list のシートを使っていないため（2026-08-25 baba要望）。
     */
    public function test_imports_monthly_sheet_with_positions(): void
    {
        $me = $this->manager();
        PersonFactory::new()->create([
            'id' => 'E-010', 'name' => '田中 健一', 'permission' => 'employee',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([
                ['name' => '田中 健一', 'role' => 'D'],
                ['name' => '鈴木 彩', 'role' => 'MC'],
                ['name' => '名簿に無い 人', 'role' => 'FC'],
            ]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        // 日程は「9月1日(火)」＝年が無い。ファイル名の 202609 から年を補う。
        $this->assertSame('2026-09-01', $p->start_date->format('Y-m-d'));
        $this->assertSame('株式会社テスト', $p->client);
        $this->assertSame('9:00', $p->start_time);
        // 「5名」「50名」「10チーム」のように単位つきでも数字だけ取り出す。
        $this->assertSame(5, $p->required_count);
        $this->assertSame(50, $p->guest_count);
        $this->assertSame(10, $p->team_count);
        $this->assertSame('確定', $p->status);
        $this->assertTrue((bool) $p->staff_published);
        // ⚠ 登録拠点が空だと案件一覧の拠点しぼりに引っかからず誰にも見えなくなる。
        $this->assertSame('東京', $p->office);

        // 名前の横のポジションがそのままアサインの役割になる。
        $this->assertSame('D', Assignment::where('staff_id', 'E-010')->firstOrFail()->role);
        $this->assertSame('MC', Assignment::where('staff_id', 'S-001')->firstOrFail()->role);
        $this->assertSame('確定', Assignment::where('staff_id', 'S-001')->firstOrFail()->status);
        // 名簿に無い人は入れずに知らせる（人の取り違えを防ぐ）。
        $this->assertSame(2, Assignment::count());
        $this->assertContains('名簿に無い 人', $res->getSession()->get('past_missing'));
    }

    /** 月シートを取り込み直しても、案件もアサインも二重にならない。 */
    public function test_monthly_sheet_can_be_imported_twice(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');
        $people = [['name' => '鈴木 彩', 'role' => 'MC']];

        $this->actingAsPerson($me)->post('/past-import', ['csv' => $this->monthlyCsv($people)]);
        $this->actingAsPerson($me)->post('/past-import', ['csv' => $this->monthlyCsv($people)]);

        $this->assertSame(1, Project::count());
        $this->assertSame(1, Assignment::count());
    }

    /**
     * ファイル名から「何年何月ぶんか」が読めない月シートは、勝手に年を決めずエラーにする。
     * ⚠ 年を勘で補うと、去年の案件が今年に入るなど静かに間違う。
     */
    public function test_monthly_sheet_without_year_in_filename_is_refused(): void
    {
        $me = $this->manager();

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([], 'アサイン表.csv'),
        ])->assertRedirect('/past-import');

        $this->assertStringContainsString('何年何月', (string) $res->getSession()->get('import_error'));
        $this->assertSame(0, Project::count());
    }

    /** 知らないポジションの書き方は、勝手に決めずに入れないで知らせる。 */
    public function test_unknown_position_is_reported_not_guessed(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'なにか']]),
        ])->assertRedirect('/past-import');

        $this->assertSame(0, Assignment::count());
        $this->assertContains('なにか', $res->getSession()->get('past_unknown_roles'));
    }

    /** 下見（preview）でも月シートとして読めていることが分かる。 */
    public function test_preview_tells_monthly_sheet(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $json = $this->actingAsPerson($me)->post('/past-import/preview', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']]),
        ])->assertOk()->json();

        $this->assertTrue($json['ok']);
        $this->assertTrue($json['isMonthly']);
        $this->assertCount(1, $json['rows']);
        $this->assertSame('2026-09-01', $json['rows'][0]['date']);
        $this->assertSame(1, $json['rows'][0]['people']);
        $this->assertSame(0, Project::count());
    }
}
