<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Support\RoleRequirementCsv;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 必要アサイン人数の取込（/role-requirement-import・2026-09-07 baba要望）。
 *
 * 見張るところ：
 *  1. 実物のリストの形（3段の規模が横並び・コンテンツ名は複数行のマス）が読める
 *  2. 備考でポジションが変わる（チェッカー→CK・軍師/サポ→SP・受付→RP）／巡回数が残る
 *  3. 「軍＝チーム」は規模の対角線だけ取る（2軍→小型のみ）
 *  4. **必ずプレビューを見せてから保存する**／プレビューで見たファイルがそのまま入る
 *  5. **CSVに無いコンテンツは触らない**／CSVにあるコンテンツは入れ替える（再取込で重複しない）
 *  6. 台帳に無い商品名は新しいコンテンツとして作る
 *  7. 管理者以上しか開けない（名簿取込と同じ）
 *  8. 解析の正本は App\Support\RoleRequirementCsv の1か所（コマンドと画面が同じものを呼ぶ）
 */
class RoleRequirementImportTest extends TestCase
{
    use RefreshDatabase;

    /** 1行を、列番号→値 の指定から組み立てる（実物と同じ30列の形にそろえる）。 */
    private function row(array $cells): string
    {
        $out = [];
        for ($i = 0; $i < 30; $i++) {
            $v = (string) ($cells[$i] ?? '');
            $out[] = '"'.str_replace('"', '""', $v).'"';
        }

        return implode(',', $out);
    }

    /**
     * 実物と同じ形の小さなCSV。
     * 「テスト合戦」＝3段（小型／中型／大型）／「テストチャンバラ」＝2軍（対角線で小型のみ）。
     */
    private function csv(): string
    {
        return implode("\n", [
            $this->row([1 => '参考リストです']),
            $this->row([]),
            // ── コンテンツ1：規模ラベルが3段そろっている ──
            $this->row([
                0  => "・テスト合戦\n・テスト合戦ミニ\n※お客様のご要望で増減します",
                1  => '参加人数：～49名',
                11 => '参加人数：50～99名',
                21 => '参加人数：100～150名',
            ]),
            $this->row([1 => 'NO', 2 => '名前', 5 => 'P', 6 => '巡回', 7 => '備考', 11 => 'NO', 15 => 'P', 21 => 'NO', 25 => 'P']),
            $this->row([5 => 'D',  15 => 'D',  25 => 'D']),
            $this->row([5 => 'MC', 15 => 'MC', 25 => 'MC']),
            $this->row([5 => 'FC', 7 => 'チェッカー', 15 => 'FC', 17 => '軍師', 25 => 'FC', 26 => '4', 27 => '巡回']),
            $this->row([15 => 'FC', 17 => 'サポ', 25 => 'FC', 26 => '4', 27 => '巡回']),
            $this->row([]),
            // ── コンテンツ2：2軍＝対角線で小型だけ取る ──
            $this->row([
                0  => "・テストチャンバラ（2軍）",
                1  => '参加人数：～49名（2軍）',
                11 => '参加人数：50～99名（2軍）',
                21 => '参加人数：100～150名（2軍）',
            ]),
            $this->row([1 => 'NO', 5 => 'P', 11 => 'NO', 15 => 'P', 21 => 'NO', 25 => 'P']),
            $this->row([5 => 'D', 15 => 'D', 25 => 'D']),
            $this->row([5 => 'FC', 7 => '受付', 15 => 'FC', 25 => 'FC']),
            $this->row([]),
        ]);
    }

    private function parsed(): array
    {
        return RoleRequirementCsv::parse(RoleRequirementCsv::rows($this->csv()));
    }

    /** 商品名（複数行のマス）を全部拾い、規模ごとに枠を分ける。 */
    public function test_it_reads_the_real_list_shape(): void
    {
        $out = $this->parsed();

        // 「・」で並んだ商品名は全部コンテンツになる。「※」の注記行は商品名にしない。
        $this->assertArrayHasKey('テスト合戦', $out);
        $this->assertArrayHasKey('テスト合戦ミニ', $out);
        $this->assertArrayNotHasKey('お客様のご要望で増減します', $out);

        // 小型＝D／MC／CK の3名、中型＝D／MC／SP／SP の4名、大型＝D／MC／FC／FC の4名。
        $this->assertCount(3, $out['テスト合戦']['小型']);
        $this->assertCount(4, $out['テスト合戦']['中型']);
        $this->assertCount(4, $out['テスト合戦']['大型']);
        // 同じブロックに並んだ2つ目の商品名にも同じ枠が入る。
        $this->assertCount(3, $out['テスト合戦ミニ']['小型']);
    }

    /** 備考でポジションが変わり、巡回数はそのまま残る。 */
    public function test_notes_change_the_position_and_patrol_is_kept(): void
    {
        $out = $this->parsed();

        $small = array_column($out['テスト合戦']['小型'], 'pos');
        $this->assertSame(['D', 'MC', 'CK'], $small);          // チェッカー → CK

        $medium = array_column($out['テスト合戦']['中型'], 'pos');
        $this->assertSame(['D', 'MC', 'SP', 'SP'], $medium);   // 軍師・サポ → SP

        // 大型の巡回列（4）は patrol に、備考はそのまま note に残る。
        $large = $out['テスト合戦']['大型'];
        $this->assertSame(4, $large[2]['patrol']);
        $this->assertSame('巡回', $large[2]['note']);
        $this->assertNull($large[0]['patrol']);

        // 受付 → RP
        $this->assertSame(['D', 'RP'], array_column($out['テストチャンバラ']['小型'], 'pos'));
    }

    /** 「軍＝チーム」は規模で決まる＝2軍は小型だけ（中型・大型には入れない）。 */
    public function test_gun_takes_only_the_diagonal_scale(): void
    {
        $out = $this->parsed();

        $this->assertArrayHasKey('小型', $out['テストチャンバラ']);
        $this->assertArrayNotHasKey('中型', $out['テストチャンバラ']);
        $this->assertArrayNotHasKey('大型', $out['テストチャンバラ']);
    }

    /** プレビュー → 確定でDBに入る。台帳に無い商品名は新しいコンテンツとして作る。 */
    public function test_preview_then_import_saves_and_creates_new_contents(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        // 「テスト合戦」だけ先に台帳にある＝残り2つが新規。
        Content::create(['id' => 'CT-001', 'content_name' => 'テスト合戦', 'active' => true]);

        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);

        $preview->assertOk();
        $preview->assertSee('コンテンツ');
        $preview->assertSee('テスト合戦ミニ');
        $preview->assertSee('新しく作る');   // 台帳に無いものには印が付く
        // ⚠ プレビューの時点ではまだ何も書き込まない。
        $this->assertSame(0, ContentRoleRequirement::count());

        $token = $preview->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAsPerson($me)
            ->post('/role-requirement-import', ['token' => $token])
            ->assertRedirect('/role-requirement-import');

        // 3コンテンツぶん入る（テスト合戦 11枠＋テスト合戦ミニ 11枠＋テストチャンバラ 2枠）。
        $this->assertSame(24, ContentRoleRequirement::count());
        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-001')->count());
        // 新しいコンテンツが台帳に増えている。
        $this->assertNotNull(Content::where('content_name', 'テスト合戦ミニ')->first());
        $this->assertNotNull(Content::where('content_name', 'テストチャンバラ')->first());
    }

    /** CSVに無いコンテンツの必要人数は消さない。CSVにあるものは入れ替える（重複しない）。 */
    public function test_it_replaces_only_contents_in_the_csv(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        Content::create(['id' => 'CT-001', 'content_name' => 'テスト合戦', 'active' => true]);
        Content::create(['id' => 'CT-050', 'content_name' => 'CSVに無いコンテンツ', 'active' => true]);
        // CSVに無いコンテンツの手入力ぶん（これは残らないといけない）。
        ContentRoleRequirement::create([
            'content_id' => 'CT-050', 'scale' => '小型', 'position' => 'D', 'count' => 1, 'sort_order' => 0,
        ]);
        // CSVにあるコンテンツの古いぶん（これは入れ替わる）。
        ContentRoleRequirement::create([
            'content_id' => 'CT-001', 'scale' => '小型', 'position' => 'MC', 'count' => 9, 'sort_order' => 0,
        ]);

        $this->importOnce($me);

        $this->assertSame(1, ContentRoleRequirement::where('content_id', 'CT-050')->count());
        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-001')->count());
        $this->assertSame(0, ContentRoleRequirement::where('content_id', 'CT-001')->where('count', 9)->count());

        // もう一度取り込んでも増えない（消して入れ直しているため）。
        $this->importOnce($me);
        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-001')->count());
    }

    /**
     * 名前の書き方だけが違う登録済みコンテンツは、**同じもの**として扱う（台帳を増やさない）。
     * 2026-09-07 baba報告「案件名がちょっとちがうだけで、登録済みのやつも新規登録されちゃう」。
     */
    public function test_a_slightly_different_name_goes_into_the_existing_content(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        // 台帳の名前は「テスト・合戦」（中黒あり）。CSVは「テスト合戦」。
        Content::create(['id' => 'CT-001', 'content_name' => 'テスト・合戦', 'active' => true]);
        // 全角英数・空白のゆれも同じものとして拾う。
        Content::create(['id' => 'CT-002', 'content_name' => 'テスト合戦 ミニ', 'active' => true]);

        $this->importOnce($me);

        // 書き方が違うだけの2件は台帳を増やさない（増えるのは「テストチャンバラ」の1件だけ）。
        $this->assertSame(3, Content::count());
        $this->assertNull(Content::where('content_name', 'テスト合戦')->first());
        $this->assertNull(Content::where('content_name', 'テスト合戦ミニ')->first());
        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-001')->count());
        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-002')->count());
    }

    /** 長音・数字は意味が変わるので、混ぜない（「謎パ」と「謎パ2」は別物）。 */
    public function test_digits_and_long_vowels_are_not_merged(): void
    {
        $same = fn ($a, $b) => RoleRequirementCsv::normalizeName($a) === RoleRequirementCsv::normalizeName($b);

        // 同じものとして拾ってよいゆれ
        $this->assertTrue($same('水合戦', '水合戦 '));
        $this->assertTrue($same('ヒラメキクエスト', 'ヒラメキ・クエスト'));
        $this->assertTrue($same('ロケットPDCA', 'ロケットＰＤＣＡ'));

        // ⚠ 混ぜてはいけないもの
        $this->assertFalse($same('謎パ', '謎パ2'));
        $this->assertFalse($same('ワールドリーダーズ', 'ワルドリダズ'));
        $this->assertFalse($same('城攻め', '城攻め（大型）'));
    }

    /** 似ているだけ（片方が片方を含む）のときは勘で寄せず、人に選ばせる。 */
    public function test_a_similar_name_is_asked_instead_of_guessed(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        // 「テスト合戦（大型）」は「テスト合戦」を含むが、別物の可能性がある。
        Content::create(['id' => 'CT-001', 'content_name' => 'テスト合戦（大型）', 'active' => true]);

        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);
        $preview->assertOk();
        $preview->assertSee('要確認');

        $item = collect($preview->viewData('summary')['items'])->firstWhere('product', 'テスト合戦');
        $this->assertSame('maybe', $item['matchType']);
        // 既定は「新しく作る」＝黙って別のコンテンツへ入れない。
        $this->assertNull($item['contentId']);
        $this->assertSame('CT-001', $item['candidates'][0]['id']);

        // 人が「これはCT-001のことです」と選んだら、そこへ入れる（新しくは作らない）。
        $this->actingAsPerson($me)->post('/role-requirement-import', [
            'token' => $preview->viewData('token'),
            'map'   => [RoleRequirementCsv::key('テスト合戦') => 'CT-001'],
        ])->assertRedirect('/role-requirement-import');

        $this->assertSame(11, ContentRoleRequirement::where('content_id', 'CT-001')->count());
        $this->assertNull(Content::where('content_name', 'テスト合戦')->first());
    }

    /**
     * 似た名前が1つも無い行でも、「新しく作る」か「台帳のどれに入れるか」を**自分で選べる**
     * （2026-09-07 baba要望「新しく作るは、新しく作るか選択できるかを自分で選べるようにしてほしい」）。
     */
    public function test_every_row_can_be_pointed_at_any_existing_content(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        // 名前がまったく似ていないコンテンツ（＝こちらの候補には出てこない）。
        Content::create(['id' => 'CT-050', 'content_name' => 'まったく別の催し', 'active' => true]);

        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);
        $preview->assertOk();
        // 台帳のすべてが選択肢に出ている（候補が無い行でも選べる）。
        $preview->assertSee('コンテンツ台帳から選ぶ');
        $preview->assertSee('まったく別の催し');

        $item = collect($preview->viewData('summary')['items'])->firstWhere('product', 'テストチャンバラ');
        $this->assertSame('new', $item['matchType']);
        $this->assertSame([], $item['candidates']);

        // 候補に出ていないコンテンツを自分で指定できる。
        $this->actingAsPerson($me)->post('/role-requirement-import', [
            'token' => $preview->viewData('token'),
            'map'   => [RoleRequirementCsv::key('テストチャンバラ') => 'CT-050'],
        ])->assertRedirect('/role-requirement-import');

        $this->assertSame(2, ContentRoleRequirement::where('content_id', 'CT-050')->count());
        $this->assertNull(Content::where('content_name', 'テストチャンバラ')->first());
    }

    /** 「選び直した内容を見直す」は、まだ取り込まない（説明文だけ新しくする）。 */
    public function test_recheck_shows_the_new_choice_without_importing(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();
        Content::create(['id' => 'CT-050', 'content_name' => 'まったく別の催し', 'active' => true]);

        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);

        $res = $this->actingAsPerson($me)->post('/role-requirement-import', [
            'token'   => $preview->viewData('token'),
            'map'     => [RoleRequirementCsv::key('テストチャンバラ') => 'CT-050'],
            'recheck' => '1',
        ]);

        $res->assertOk();
        $res->assertSee('台帳の「まったく別の催し」に入れます', false);
        // まだ何も入っていない。
        $this->assertSame(0, ContentRoleRequirement::count());
        $this->assertSame(1, Content::count());
    }

    /** 2つの行が同じ台帳を指していたら取り込まない（あとの行だけ残る事故を防ぐ）。 */
    public function test_it_refuses_when_two_rows_point_at_the_same_content(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        Content::create(['id' => 'CT-001', 'content_name' => 'テスト合戦', 'active' => true]);

        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);

        $res = $this->actingAsPerson($me)->post('/role-requirement-import', [
            'token' => $preview->viewData('token'),
            // 「テスト合戦ミニ」も CT-001 に入れる指定＝ぶつかる
            'map'   => [RoleRequirementCsv::key('テスト合戦ミニ') => 'CT-001'],
        ]);

        $res->assertOk();               // 取り込まずに、同じ画面で知らせる
        $res->assertSee('取り込めません');
        $this->assertSame(0, ContentRoleRequirement::count());
    }

    /** 確認した内容が見つからないとき（時間が経った・開き直した）は、断って作り直させる。 */
    public function test_a_stale_token_is_refused(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        $this->actingAsPerson($me)
            ->post('/role-requirement-import', ['token' => str_repeat('a', 32)])
            ->assertRedirect('/role-requirement-import')
            ->assertSessionHas('import_error');

        $this->assertSame(0, ContentRoleRequirement::count());
    }

    /** 読み取れないCSVは、断ってやり直してもらう（黙って0件で通さない）。 */
    public function test_an_unreadable_csv_is_refused(): void
    {
        Storage::fake('local');
        $me = PersonFactory::new()->manager()->create();

        $file = UploadedFile::fake()->createWithContent('ちがう.csv', "氏名,所属\n山田,イベプラ\n");
        $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file])
            ->assertRedirect()
            ->assertSessionHas('import_error');
    }

    /** 取込は管理者以上（名簿取込と同じ決まり）。 */
    public function test_only_manager_and_above_can_open(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $this->actingAsPerson($staff)->get('/role-requirement-import')->assertRedirect();

        // 一般社員は「見てはいけない」ので断られる（403）。スタッフは自分の画面へ戻される。
        $employee = PersonFactory::new()->create();
        $this->actingAsPerson($employee)->get('/role-requirement-import')->assertForbidden();

        $manager = PersonFactory::new()->manager()->create();
        $this->actingAsPerson($manager)->get('/role-requirement-import')->assertOk();
    }

    /** CSV一括取込のハブに入口が出ている（画面から辿れないと使われない）。 */
    public function test_the_hub_links_to_this_screen(): void
    {
        $me = PersonFactory::new()->manager()->create();
        $this->actingAsPerson($me)->get('/imports')
            ->assertOk()
            ->assertSee('/role-requirement-import');
    }

    /**
     * 実物のリスト（同梱の初期データ）が、これまでと同じ数だけ読める。
     * ⚠ ここが変わったら解析を壊している（コンテンツ43件・枠1017件）。
     */
    public function test_the_real_list_still_reads_the_same(): void
    {
        $path = database_path('data/role_requirements.csv');
        $this->assertFileExists($path);

        $out = RoleRequirementCsv::parse(RoleRequirementCsv::rows((string) file_get_contents($path)));
        $summary = RoleRequirementCsv::summary($out);

        $this->assertSame(43, $summary['contentCount']);
        $this->assertSame(1017, $summary['slotTotal']);
    }

    /** プレビュー→確定を1回通す（テストの中で繰り返し使う）。 */
    private function importOnce($me): void
    {
        $file = UploadedFile::fake()->createWithContent('必要アサイン人数.csv', $this->csv());
        $preview = $this->actingAsPerson($me)
            ->post('/role-requirement-import/preview', ['csv' => $file]);
        $preview->assertOk();

        $this->actingAsPerson($me)
            ->post('/role-requirement-import', ['token' => $preview->viewData('token')])
            ->assertRedirect('/role-requirement-import');
    }
}
