<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\SheetImportSkip;
use App\Support\SheetSkipMemory;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 取込で「取り込まない」にした案件を覚える（2026-10-21 baba「毎回すでにチェックしたことをチェック外すのめんどう」）。
 *
 * 例：11/7 小間久＝名古屋の人がECSにだけ入っている＝取り込むたびに「消える人」と出て毎回外していた。
 * 決まり＝差分が前回外したときと同じなら最初から外す／変わったらいつもどおり見せる／取り込んだら忘れる。
 * 正本＝App\Support\SheetSkipMemory。
 */
class SheetSkipMemoryTest extends TestCase
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

    /** 実物と同じ見出しのCSV（SheetDiffPreviewTest と同じ形）。 */
    private function csv(array $row): UploadedFile
    {
        $header = 'No.,募集,種別,日程,宿泊,コンテンツ,案件規模,営業担当,オンラインツール,配信種別,'
            .'顧客名(代理店名),運営場所,複数開催,集合,解散,拘束,入場,開始,終了,顧客担当名,'
            .'人数,チーム数,運営人数,形式,運営方式,担当,LINE作成,LINE概要送付,引継,ダブチェ,'
            .'運営シート,シート期日,台本,台本期日,音響,ロゴ,カメラ,記事,動画,会場場所,'
            .'集合形式,お酒,物品担当,ケータリング,移動方法,会場種別,備考,D,MC,OP,スタッフ';
        $line = implode(',', array_map(
            fn ($v) => str_contains((string) $v, ',') ? '"'.str_replace('"', '""', (string) $v).'"' : (string) $v,
            $row
        ));

        return UploadedFile::fake()->createWithContent('past.csv', $header."\n".$line."\n");
    }

    private function row(array $override = []): array
    {
        $base = array_fill(0, 51, '');
        $base[0] = '1';
        $base[3] = '2026-01-20';
        $base[5] = '水合戦';
        $base[10] = '株式会社テスト';
        $base[13] = '08:00';
        $base[22] = '5';
        foreach ($override as $i => $v) {
            $base[$i] = $v;
        }

        return $base;
    }

    private function preview(Person $me, array $row): array
    {
        return $this->actingAsPerson($me)->post('/past-import/preview', ['csv' => $this->csv($row)])
            ->assertOk()->json('rows.0');
    }

    private function import(Person $me, array $row, array $edits = []): void
    {
        $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->csv($row),
            'edits' => $edits === [] ? '' : json_encode($edits),
        ])->assertRedirect('/past-import');
    }

    /**
     * いちばん大事な流れ：外して取り込む → 次も同じなら覚えている → アサイン表が変わったら見せる → 取り込んだら忘れる。
     */
    public function test_a_skipped_project_stays_skipped_until_the_sheet_changes(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田太郎');
        $this->staff('S-002', '鈴木花子');

        // ECSには山田・鈴木（鈴木はECSだけで入れた人のつもり）。アサイン表には山田だけ。
        $this->import($me, $this->row([50 => '山田太郎, 鈴木花子']));
        $sheet = $this->row([50 => '山田太郎']);

        $r = $this->preview($me, $sheet);
        $this->assertSame('changed', $r['diff']['kind']);
        $this->assertNotNull($r['skipMemo'], '「変わります」の案件なのに、覚えるための鍵が返っていません。');
        $this->assertFalse($r['skipMemo']['remembered'], 'まだ外していないのに「前回も外した」になっています。');

        // チェックを外して取り込む＝鈴木は消えない＋差分を覚える。
        $this->import($me, $sheet, ['0' => ['skip' => true, 'keepTarget' => $r['skipMemo']['target'], 'keepFp' => $r['skipMemo']['fp']]]);
        $this->assertSame(1, SheetImportSkip::count());
        $this->assertTrue(Assignment::where('staff_id', 'S-002')->exists(), '外したのに鈴木さんが消えています。');

        // 次の朝、同じアサイン表 → はじめから外す。
        $again = $this->preview($me, $sheet);
        $this->assertTrue($again['skipMemo']['remembered'], '同じ中身なのに「前回も外した」になっていません＝また毎回外すことになります。');
        $this->assertFalse($again['skipMemo']['changedSince']);

        // アサイン表で運営人数が直った → 黙って外さず、見せる。
        $changed = $this->preview($me, $this->row([50 => '山田太郎', 22 => '9']));
        $this->assertFalse($changed['skipMemo']['remembered'], 'アサイン表が変わったのに外したままです＝見落とします。');
        $this->assertTrue($changed['skipMemo']['changedSince']);

        // 取り込んだら忘れる。
        $this->import($me, $sheet);
        $this->assertSame(0, SheetImportSkip::count(), '取り込んだのに「外している」と覚えたままです。');
    }

    /** 変化なし・新規の案件は覚えない（変化なしは自動で外れる／新規はECSの案件が無い）。 */
    public function test_same_and_new_projects_carry_no_memo(): void
    {
        $me = $this->manager();

        $this->assertNull($this->preview($me, $this->row())['skipMemo'], '新規の案件に鍵が付いています。');

        $this->import($me, $this->row());
        $this->assertNull($this->preview($me, $this->row())['skipMemo'], '変化なしの案件に鍵が付いています。');
    }

    /** 外しただけ（鍵なし）や、ありもしない案件IDでは覚えない。 */
    public function test_nothing_is_remembered_without_a_valid_key(): void
    {
        $me = $this->manager();
        $this->import($me, $this->row([22 => '5']));

        $this->import($me, $this->row([22 => '9']), ['0' => ['skip' => true]]);
        $this->import($me, $this->row([22 => '9']), ['0' => ['skip' => true, 'keepTarget' => 'P-9999-9999', 'keepFp' => str_repeat('a', 32)]]);
        $this->import($me, $this->row([22 => '9']), ['0' => ['skip' => true, 'keepTarget' => 'P-9999-9999', 'keepFp' => 'こわれた指紋']]);

        $this->assertSame(0, SheetImportSkip::count());
    }

    /** 指紋は人の並び順では変わらない（同じ中身なのに「変わった」と見せない）。 */
    public function test_the_fingerprint_ignores_the_order_of_people(): void
    {
        $a = ['changes' => [], 'people' => ['add' => ['A', 'B'], 'remove' => []]];
        $b = ['changes' => [], 'people' => ['remove' => [], 'add' => ['B', 'A']]];
        $c = ['changes' => [], 'people' => ['add' => ['A', 'C'], 'remove' => []]];

        $this->assertSame(SheetSkipMemory::fingerprint($a), SheetSkipMemory::fingerprint($b));
        $this->assertNotSame(SheetSkipMemory::fingerprint($a), SheetSkipMemory::fingerprint($c));
    }

    /** 画面に「前回も外した」の札と、外したときに鍵を送るしかけがあること（JSが消えていないかの見張り）。 */
    public function test_the_screen_has_the_memo_parts(): void
    {
        $res = $this->actingAsPerson($this->manager())->get('/past-import')->assertOk();
        $res->assertSee('前回も外した', false);
        $res->assertSee('keepFp', false);
        $res->assertSee('data-memo-target', false);
    }
}
