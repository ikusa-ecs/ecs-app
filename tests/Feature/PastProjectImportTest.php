<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectShare;
use App\Support\EventCount;
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
        string $date = '9月1日(火)', string $content = '会議室', string $type = '',
        string $marks = ''): UploadedFile
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
        // 「追加案件, メンバー募集なし」などの印。実物は種別の上あたりに項目名なしで入る。
        $rows[] = $put($blank(), [13 => $marks]);
        // 種別（＝実施形態）。実物は項目名が付かず、日程のすぐ上に「イベント東(リアル)」と入る。
        $rows[] = $put($blank(), [13 => $type]);
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

    /**
     * 実施形態は「種別」から読む（2026-08-26 baba確認）。
     *
     * ⚠ 実物の月シートでは、種別だけ**項目名が付いていない**（日程のすぐ上に
     *   「イベント東(リアルロング)」と入る）。ここが読めていなかったため、
     *   取り込んだ案件の実施形態が全部空になっていた。
     * ⚠ 「形式」は実施形態ではない（中身は「イベプラD＋メンバー」など）。実施形態に使わない。
     * ⚠ カッコの前（イベント東）は捨てる＝拠点は projects.office（画面で選ぶ登録拠点）が正。
     */
    public function test_monthly_sheet_reads_format_from_type_cell(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室', 'イベント東(リアルロング)'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertSame('リアルロング', $p->format);
    }

    /**
     * 種別のところに知らない書き方が入っていたら、勘で実施形態にせず
     * 「取り込まなかった項目」として知らせる。
     * ⚠ 勘で入れると集計（イベント数・種別別の件数）が静かに狂う。
     */
    public function test_monthly_sheet_unknown_type_is_reported_not_guessed(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室', 'イベント東(まだ無い形態)'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertNull($p->format);
        $this->assertStringContainsString('まだ無い形態', (string) $res->getSession()->get('status'));
    }

    /**
     * 日程の上に書かれた印（追加案件・ヨミ）も取り込む（2026-08-26 baba要望）。
     * 1つのセルに「追加案件, メンバー募集なし」と2つ入っていても読む。
     * ⚠ 「メンバー募集なし」は過去案件が必ず「募集しない」で入るので何もしない。
     *   ただし「取り込まなかった項目」に出してはいけない（本当の見落としが埋もれるため）。
     */
    public function test_monthly_sheet_reads_marks_above_date(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室',
                'イベント東(リアル)', '追加案件, メンバー募集なし'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertSame('リアル', $p->format);
        $this->assertSame('追加案件', $p->category);
        // 過去案件は募集しない（スタッフ画面に「募集中」として出さないため）。
        $this->assertFalse((bool) $p->is_recruiting);
        $this->assertStringNotContainsString('メンバー募集なし', (string) $res->getSession()->get('status'));
    }

    /** ヨミ（確度）も日程の上の印から入る。 */
    public function test_monthly_sheet_reads_yomi_mark(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室', 'イベント東(リアル)', 'Aヨミ'),
        ])->assertRedirect('/past-import');

        $this->assertSame('Aヨミ', Project::where('project_name', '会議室')->firstOrFail()->yomi);
    }

    /**
     * キャンセルは備考に書き、イベント数には数えない（2026-08-26 baba要望）。
     * ⚠ 案件の状態（status）に「キャンセル」は無い（未着手/調整中/確定/完了の4つ）。
     *   状態を増やすとアサイン画面の進み方まで作り直しになるため、いまはこの形。
     */
    public function test_monthly_sheet_cancelled_project_is_noted_and_not_counted(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室', 'キャンセル'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertStringContainsString('キャンセル', (string) $p->note);
        // シートの備考も消えずに残る。
        $this->assertStringContainsString('テスト', (string) $p->note);
        $this->assertFalse((bool) $p->count_as_event);
        $this->assertFalse(EventCount::counts($p));
        // 実施形態は空のまま（キャンセルは実施形態ではない）。
        $this->assertNull($p->format);
    }

    /**
     * 他拠点からの巻き取りは備考に書き残す。
     * ⚠ どの拠点から来たのかがシートに書かれていないので、拠点間共有（project_shares）は作らない
     *   （拠点を勘で決めると拠点別の集計が狂う）。
     */
    public function test_monthly_sheet_takeover_is_noted_only(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202609.csv', '9月1日(火)', '会議室', 'イベント他拠点東(巻き取り)'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertStringContainsString('他拠点から巻き取り', (string) $p->note);
        $this->assertSame(0, ProjectShare::count());
    }

    /**
     * 「これからの案件」として取り込むと、案件は調整中・未公開・募集する／アサインは仮で入る
     * （2026-08-26 baba要望）。
     *
     * ⚠ なぜこの形か＝アサイン表からしか人は取り込めないのに、過去あつかいだと全部「確定」で
     *   入ってしまう（これからの10月のアサイン表が入れられない）。逆に案件CSV取込は人が入らない。
     * ⚠ **未公開**で入れるのが要点。取り込んだ瞬間にクライアント名・会場がスタッフ全員に
     *   見えないようにし、公開の入口は公開ボードの1つだけ、という決まりを崩さない。
     */
    public function test_future_mode_imports_as_tentative_and_unpublished(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $res = $this->actingAsPerson($me)->post('/past-import', [
            'mode' => 'これから',
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202610.csv', '10月1日(木)', '会議室', 'イベント東(リアル)'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertSame('2026-10-01', $p->start_date->format('Y-m-d'));
        $this->assertSame('調整中', $p->status);
        $this->assertFalse((bool) $p->staff_published, 'スタッフには見えないこと');
        $this->assertTrue((bool) $p->is_recruiting, '募集する案件として入ること');

        $a = Assignment::where('staff_id', 'S-001')->firstOrFail();
        $this->assertSame('仮', $a->status);
        $this->assertNull($a->confirmed_at, '仮なので確定の記録は付かないこと');
        // 押す前に分かるよう、結果のメッセージにも「未公開」と出す。
        $this->assertStringContainsString('未公開', (string) $res->getSession()->get('status'));
    }

    /** 「これからの案件」でも、シートに「メンバー募集なし」とあれば募集しない。 */
    public function test_future_mode_respects_no_recruit_mark(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'mode' => 'これから',
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']],
                '東京アサイン表 - 202610.csv', '10月1日(木)', '会議室',
                'イベント東(リアル)', 'メンバー募集なし'),
        ])->assertRedirect('/past-import');

        $this->assertFalse((bool) Project::where('project_name', '会議室')->firstOrFail()->is_recruiting);
    }

    /**
     * 扱いを指定しなければ今までどおり「過去の実績」（確定・公開済み）。
     * ⚠ 知らない値が来たときも過去あつかいにする＝事故で「誰にも見えない案件」にしない。
     */
    public function test_mode_defaults_to_past(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($me)->post('/past-import', [
            'mode' => 'よく分からない値',
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'MC']]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '会議室')->firstOrFail();
        $this->assertSame('確定', $p->status);
        $this->assertTrue((bool) $p->staff_published);
        $this->assertSame('確定', Assignment::where('staff_id', 'S-001')->firstOrFail()->status);
    }

    /**
     * 「これから」で入れたあと「過去」で入れ直すと、案件もアサインも切り替わる
     * （＝先に過去で入れてしまっても、入れ直しで直せる）。
     */
    public function test_reimport_switches_between_modes(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '鈴木 彩');
        $people = [['name' => '鈴木 彩', 'role' => 'MC']];

        $this->actingAsPerson($me)->post('/past-import',
            ['csv' => $this->monthlyCsv($people)]);
        $this->assertSame('確定', Assignment::where('staff_id', 'S-001')->firstOrFail()->status);

        $this->actingAsPerson($me)->post('/past-import',
            ['mode' => 'これから', 'csv' => $this->monthlyCsv($people)]);

        $this->assertSame(1, Project::count(), '案件が二重にならないこと');
        $this->assertSame(1, Assignment::count(), 'アサインも二重にならないこと');
        $p = Project::firstOrFail();
        $this->assertSame('調整中', $p->status);
        $this->assertFalse((bool) $p->staff_published);
        $this->assertSame('仮', Assignment::where('staff_id', 'S-001')->firstOrFail()->status);
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

    /**
     * 「どの拠点の案件として入れるか」を画面で選べる（2026-08-25 baba）。
     *
     * ⚠ 取り込んだ人の拠点で決め打ちにすると、東北のアサイン表を東京の方が取り込んだとき
     *   東京の案件として入り、東北の案件一覧に出てこない。
     */
    public function test_office_can_be_chosen_on_import(): void
    {
        $me = $this->manager();   // 拠点＝東京

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([$this->row()]),
            'office' => '東北',
        ])->assertRedirect('/past-import');

        $this->assertSame('東北', Project::where('project_name', '水合戦')->firstOrFail()->office);
    }

    /**
     * 知らない拠点名が送られてきたら、自分の拠点にする。
     * ⚠ そのまま入れると、どの拠点の一覧にも出てこない案件になってしまう。
     */
    public function test_unknown_office_falls_back_to_own_office(): void
    {
        $me = $this->manager();   // 拠点＝東京

        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv([$this->row()]),
            'office' => 'ありえない拠点',
        ])->assertRedirect('/past-import');

        $this->assertSame('東京', Project::where('project_name', '水合戦')->firstOrFail()->office);
    }

    /**
     * ========= 取込画面でその場で直せる（2026-08-25 baba要望）=========
     *
     * 【なぜこの作りか】CSVそのものは今までどおりサーバーが読み、画面から送るのは
     * 「上書きする値」だけ。⚠ 画面にもう1つ読み取りを書くと、月シート対応のときのように
     * 片方だけ直して食い違う事故が起きる。
     */

    /** 画面で直した日程・コンテンツ・顧客名・運営人数が、その内容で登録される。 */
    public function test_edits_from_the_screen_are_used_on_import(): void
    {
        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->csv([$this->row([3 => '1/20'])]),   // 年が無い＝そのままではエラー
            'edits' => json_encode([
                0 => [
                    'date' => '2026-03-05',
                    'name' => '謎解き',
                    'client' => '直した株式会社',
                    'count' => '8',
                ],
            ]),
        ]);

        $res->assertRedirect('/past-import');

        $p = Project::firstOrFail();
        $this->assertSame('2026-03-05', $p->start_date->format('Y-m-d'), '直した日程で入ること');
        $this->assertSame('謎解き', $p->project_name);
        $this->assertSame('直した株式会社', $p->client);
        $this->assertSame(8, $p->required_count);
        $this->assertStringNotContainsString('日程が読めません', (string) session('status'));
    }

    /** 「取り込まない」に印を付けた案件は入らない（他の案件は入る）。 */
    public function test_rows_marked_skip_are_not_imported(): void
    {
        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->csv([
                $this->row([5 => '入れない案件']),
                $this->row([0 => '2', 5 => '入れる案件']),
            ]),
            'edits' => json_encode([0 => ['skip' => true]]),
        ]);

        $res->assertRedirect('/past-import');
        $this->assertSame(1, Project::count());
        $this->assertNotNull(Project::where('project_name', '入れる案件')->first());
        $this->assertNull(Project::where('project_name', '入れない案件')->first());
        $this->assertStringContainsString('1件は入れていません', (string) session('status'));
    }

    /** 「取り込まない」に印を付けた案件は、アサインも入らない。 */
    public function test_skipped_row_creates_no_assignment(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->csv([$this->row([50 => '鈴木 彩'])]),
            'edits' => json_encode([0 => ['skip' => true]]),
        ])->assertRedirect('/past-import');

        $this->assertSame(0, Project::count());
        $this->assertSame(0, Assignment::count());
    }

    /** 下見も直した内容で判定し直す（エラーが消えたことを画面で確かめられる）。 */
    public function test_preview_reflects_edits(): void
    {
        $me = $this->manager();

        // 直す前＝年が無いのでエラー。
        $before = $this->actingAsPerson($me)->post('/past-import/preview', [
            'csv' => $this->csv([$this->row([3 => '1/20'])]),
        ])->assertOk()->json();
        $this->assertNotSame([], $before['rows'][0]['errors']);

        // 直したあと＝エラーが消え、直した値が返る。
        $after = $this->actingAsPerson($me)->post('/past-import/preview', [
            'csv' => $this->csv([$this->row([3 => '1/20'])]),
            'edits' => json_encode([0 => ['date' => '2026-03-05', 'name' => '謎解き']]),
        ])->assertOk()->json();

        $this->assertSame([], $after['rows'][0]['errors']);
        $this->assertSame('2026-03-05', $after['rows'][0]['date']);
        $this->assertSame('謎解き', $after['rows'][0]['name']);
        $this->assertSame(0, $after['rows'][0]['index'], '何件目かが返ること（直した内容を当てる鍵）');

        // 下見なので、やはり1件も登録しない。
        $this->assertSame(0, Project::count());
    }

    /** 「取り込まない」の印は、確かめ直しても消えない（画面がチェックを描き直せるように）。 */
    public function test_preview_returns_skip_flag(): void
    {
        $json = $this->actingAsPerson($this->manager())->post('/past-import/preview', [
            'csv' => $this->csv([$this->row(), $this->row([0 => '2', 5 => '別案件'])]),
            'edits' => json_encode([1 => ['skip' => true]]),
        ])->assertOk()->json();

        $this->assertFalse($json['rows'][0]['skip']);
        $this->assertTrue($json['rows'][1]['skip']);
    }

    /** 空にした項目は「空にした」として扱う＝必須ならエラーにする（CSVの値に戻さない）。 */
    public function test_clearing_a_required_field_becomes_an_error(): void
    {
        $res = $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->csv([$this->row()]),
            'edits' => json_encode([0 => ['name' => '']]),
        ]);

        $res->assertRedirect('/past-import');
        $this->assertSame(0, Project::count());
        $this->assertStringContainsString('コンテンツ（案件名）が空です', (string) session('status'));
    }

    /** 直した内容が壊れて届いても、CSVのまま取り込む（画面の不調で取込ごと落とさない）。 */
    public function test_broken_edits_are_ignored(): void
    {
        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->csv([$this->row()]),
            'edits' => 'これはJSONではない',
        ])->assertRedirect('/past-import');

        $p = Project::firstOrFail();
        $this->assertSame('水合戦', $p->project_name, 'CSVの値で入ること');
    }

    /** 月ごとのアサイン表でも、その場で直せる。 */
    public function test_monthly_sheet_can_be_edited_too(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->monthlyCsv([['name' => '鈴木 彩', 'role' => 'FC']]),
            'edits' => json_encode([0 => ['client' => '直した株式会社', 'count' => '12']]),
        ])->assertRedirect('/past-import');

        $p = Project::firstOrFail();
        $this->assertSame('直した株式会社', $p->client);
        $this->assertSame(12, $p->required_count);
        // 人はCSVのまま入る（人の直しはこの画面の対象外＝名簿で直す）。
        $this->assertSame(1, Assignment::count());
    }

    /** 取込画面に、その場で直すための入れ物がある。 */
    public function test_screen_has_editable_table(): void
    {
        $html = $this->actingAsPerson($this->manager())->get('/past-import')->assertOk()->getContent();

        $this->assertStringContainsString('id="pjEdits"', $html, '直した内容を送る入れ物');
        $this->assertStringContainsString('pjRecheck()', $html, '確かめ直すボタン');
        $this->assertStringContainsString('取込', $html);
    }

    /**
     * 名古屋のアサイン表の形（1案件＝横1ブロック）のCSVを作る。
     *
     * 東京との違いは3つ（2026-08-27 に実物で判明）。ここを間違えると案件がまるごと入らない。
     *   ① 「日程」が「日」と「程」の2セルに割れていて、あいだに曜日を出すための値が入る
     *   ② スタッフで埋める予定の枠に「メンバー」と書いて場所を取ってある
     *   ③ 名前の頭に「★」「☆」が付く（スタッフの目印。名簿には付いていない）
     *
     * @param  list<array{name:string, role:string}>  $people
     */
    private function nagoyaCsv(array $people, string $filename = '名古屋アサイン表2026 - 202609.csv',
        string $enterTime = ''): UploadedFile
    {
        $blank = fn () => array_fill(0, 24, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };

        $rows = [];
        $rows[] = $put($blank(), [13 => '1']);
        $rows[] = $put($blank(), [13 => 'LINE登録済']);              // アサイン状況（ECSには入れない）
        $rows[] = $put($blank(), [13 => 'イベント名（リアル）']);       // 種別＝カッコの中だけ使う
        // ① 「日」＋（曜日用の値）＋「程」＋ 本当の日程
        $rows[] = $put($blank(), [13 => '日', 14 => '1/2(火)', 15 => '程', 16 => '9月3日(木)']);
        $rows[] = $put($blank(), [13 => 'コンテンツ', 16 => '水合戦']);
        $rows[] = $put($blank(), [13 => '顧客名（代理店名）', 16 => '株式会社なごや']);
        $rows[] = $put($blank(), [13 => '集合/解散/拘束時間', 16 => '8:00', 18 => '19:00', 20 => '11:00']);
        // 入場が空でも、開始・終了がずれない（同じ表の集合/解散/拘束の位置から学習する）
        $rows[] = $put($blank(), [13 => '入場 / 開始 / 終了', 16 => $enterTime, 18 => '14:00', 20 => '16:30']);
        $rows[] = $put($blank(), [13 => '運営人数 / 形式']);          // ⚠ 名古屋はここが空のことが多い
        $rows[] = $put($blank(), [13 => 'NO', 14 => '名前', 17 => 'P']);
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
     * 名古屋の形（「日」＋「程」に割れた日程）でも取り込める。
     *
     * ⚠ ここが読めないと**案件がまるごと入らない**。ECSは「日程・コンテンツ・顧客名」が
     *   縦にそろう列を1件目の始まりにするので、割れていると1件目の位置を取り違え、
     *   その左にある案件が全部無視される（実物の7月シートで12件が消えていた）。
     */
    public function test_nagoya_split_date_label_is_read(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->nagoyaCsv([['name' => '鈴木 彩', 'role' => 'MC']]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame('2026-09-03', $p->start_date->format('Y-m-d'), '「日」「程」に割れていても日程を読む');
        $this->assertSame('株式会社なごや', $p->client);
        $this->assertSame('リアル', $p->format, '種別のカッコの中だけを実施形態にする');
    }

    /**
     * 「メンバー」は人ではなく「スタッフで埋める空き枠」＝人として取り込まない。
     * 運営人数がシートに無いときは「入っている人＋空き枠」で埋める（2026-08-27 baba選択）。
     */
    public function test_nagoya_member_slots_are_counted_not_imported_as_people(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->nagoyaCsv([
                ['name' => '鈴木 彩', 'role' => 'MC'],
                ['name' => 'メンバー', 'role' => ''],
                ['name' => 'メンバー', 'role' => ''],
            ]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        // 人として入るのは1人だけ（「メンバー」は入らない）。
        $this->assertSame(1, Assignment::where('project_id', $p->id)->count());
        // 運営人数＝1人＋空き枠2＝3。
        $this->assertSame(3, $p->required_count);
    }

    /** 名前の頭の「★」「☆」は目印なので、名簿と照合するときは無いものとして扱う。 */
    public function test_star_marks_on_names_still_match_the_roster(): void
    {
        $staff = $this->staff('S-002', '永松 一子');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->nagoyaCsv([['name' => '★永松 一子', 'role' => 'FC']]),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertDatabaseHas('assignments', [
            'project_id' => $p->id,
            'staff_id' => $staff->id,
        ]);
    }

    /**
     * 3つ並ぶ欄（入場／開始／終了）で途中が空でも、値が1つずつずれない。
     * ⚠ ずれると**本番の時間が違って入る**（実物の名古屋7月シートで起きていた）。
     */
    public function test_missing_middle_time_does_not_shift_the_others(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->nagoyaCsv([['name' => '鈴木 彩', 'role' => 'MC']]),   // 入場は空
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertNull($p->event_enter_time, '入場は空のまま');
        $this->assertSame('14:00', $p->event_start_time, '開始がずれて入場に入らない');
        $this->assertSame('16:30', $p->event_end_time);
    }

    /** 入場も埋まっているときは、そのまま3つとも入る（学習した位置で壊れない）。 */
    public function test_all_three_times_are_read_when_filled(): void
    {
        $this->staff('S-001', '鈴木 彩');

        $this->actingAsPerson($this->manager())->post('/past-import', [
            'csv' => $this->nagoyaCsv([['name' => '鈴木 彩', 'role' => 'MC']], '名古屋アサイン表2026 - 202609.csv', '13:00'),
        ])->assertRedirect('/past-import');

        $p = Project::where('project_name', '水合戦')->firstOrFail();
        $this->assertSame('13:00', $p->event_enter_time);
        $this->assertSame('14:00', $p->event_start_time);
        $this->assertSame('16:30', $p->event_end_time);
    }
}
