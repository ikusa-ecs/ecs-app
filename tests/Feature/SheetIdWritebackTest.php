<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use App\Models\SheetSync;
use App\Support\MonthlySheetReader;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ECSの案件IDをアサイン表へ書き戻すための対応表（2026-09-15 baba要望）。
 *
 * 【babaの言葉】「ID書き戻したほうがECSとの連携がうまくいくなら書いたほうがいい。
 *   書くならアサイン表に行を追加はできないからアサイン表の100行目とかかな。
 *   そこなら今アサイン表の下は空白だから変更しても問題ない」
 *
 * 【なぜ要るか】いまは「日付・コンテンツ・お客様名」が同じかどうかで結び付けている。
 *   シート側で名前を直されると別の案件と見なされ、同じ案件が2つできてしまう。
 *   ECSのIDがシートに書いてあれば確実に分かる。
 *
 * ⚠ 対応表は「取り込んだ結果そのもの」を残す。あとで探し直さない
 *   （探し直すと、似ている別の案件のIDを書き戻すことがある）。
 * ⚠ 書き戻す行は SheetSyncController::ID_ROW の1か所が正本（GASの手順書にも同じ数字）。
 */
class SheetIdWritebackTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef';

    /** 1案件ぶんの横幅（MonthlySheetReader の決め打ちと同じ）。 */
    private const BLOCK_WIDTH = 10;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 月ごとのアサイン表（1案件＝横1ブロック）の中身。2案件ぶん横に並べる。 */
    private function monthlyRows(): array
    {
        $width = self::BLOCK_WIDTH;
        $cols = 13 + $width * 2 + 2;
        $blank = fn () => array_fill(0, $cols, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };

        // 1件目は13列目から、2件目はその BLOCK_WIDTH ぶん右から始まる。
        $a = 13;
        $b = 13 + $width;

        $rows = [];
        $rows[] = $put($blank(), [$a => '1', $b => '2']);
        $rows[] = $put($blank(), [$a => '', $b => '']);
        $rows[] = $put($blank(), [$a => '', $b => '']);
        $rows[] = $put($blank(), [
            $a => '日程', $a + 3 => '9月1日(火)', $a + 6 => '宿泊', $a + 7 => '無',
            $b => '日程', $b + 3 => '9月2日(水)', $b + 6 => '宿泊', $b + 7 => '無',
        ]);
        $rows[] = $put($blank(), [$a => 'コンテンツ', $a + 3 => '会議室', $b => 'コンテンツ', $b + 3 => '水合戦']);
        $rows[] = $put($blank(), [$a => '案件規模', $a + 3 => '小型', $b => '案件規模', $b + 3 => '小型']);
        $rows[] = $put($blank(), [
            $a => '顧客名（代理店名）', $a + 3 => '株式会社テスト',
            $b => '顧客名（代理店名）', $b + 3 => '株式会社サンプル',
        ]);
        $rows[] = $put($blank(), [
            $a => '集合/解散/拘束時間', $a + 3 => '9:00', $a + 5 => '17:00',
            $b => '集合/解散/拘束時間', $b + 3 => '10:00', $b + 5 => '18:00',
        ]);
        $rows[] = $put($blank(), [$a => '運営人数 / 形式', $a + 3 => '5名', $b => '運営人数 / 形式', $b + 3 => '5名']);
        $rows[] = $put($blank(), [
            $a => 'NO', $a + 1 => '名前', $a + 4 => 'P',
            $b => 'NO', $b + 1 => '名前', $b + 4 => 'P',
        ]);

        return $rows;
    }

    private function send(array $rows): SheetSync
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        $this->postJson('/sheet-sync', [
            'token' => self::TOKEN,
            'book' => '2026年東京アサイン表9月～10月',
            'tab' => '202609',
            'office' => '東京',
            'rows' => $rows,
        ])->assertOk();

        return SheetSync::firstOrFail();
    }

    public function test_取り込むと列とECSのIDの対応表が残る(): void
    {
        $me = $this->manager();
        $sync = $this->send($this->monthlyRows());

        // まだ取り込んでいないので対応表は空。
        $this->assertNull($sync->project_ids);

        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])
            ->assertRedirect('/past-import');

        $ids = $sync->fresh()->project_ids;
        $this->assertIsArray($ids);
        $this->assertCount(2, $ids, '2案件ぶんの対応表が残っていない');

        // 列番号（0はじまり）がキー。中身は実際にできた案件のID。
        $width = self::BLOCK_WIDTH;
        $this->assertArrayHasKey(13, $ids);
        $this->assertArrayHasKey(13 + $width, $ids);

        $made = Project::pluck('id')->all();
        foreach ($ids as $id) {
            $this->assertContains($id, $made, '実際には無い案件のIDが入っている');
        }
    }

    public function test_毎朝の返事で対応表と書き戻す行を返す(): void
    {
        $me = $this->manager();
        $rows = $this->monthlyRows();
        $sync = $this->send($rows);

        $this->actingAsPerson($me)->post('/past-import', ['sync' => $sync->id])
            ->assertRedirect('/past-import');

        // 次の朝＝同じ中身がまた届く。そのときの返事に対応表が入っている。
        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $res = $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows,
        ])->assertOk();

        // ⚠ 書き戻す行は100行目（baba指定）。行を増やさない＝レイアウトを壊さない。
        $res->assertJsonPath('idRow', 100);
        $this->assertNotEmpty($res->json('projectIds'), '対応表が返っていない');
    }

    /** ⚠ 取り込む前は空（まだECSにIDが無いので、書き戻すものが無い）。 */
    public function test_取り込む前は対応表を返さない(): void
    {
        $rows = $this->monthlyRows();
        $res = $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows,
        ]);

        config(['ecs.sheet_sync_token' => self::TOKEN]);
        $res = $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'tab' => '202609', 'office' => '東京', 'rows' => $rows,
        ])->assertOk();

        $this->assertSame([], (array) $res->json('projectIds'));
    }
}
