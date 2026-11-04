<?php

namespace Tests\Feature;

use App\Models\ShiftPreference;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサイン表の名簿の「希望」（K28から下）をECSから返す（2026-11-04 小沼さん要望）。
 *
 * 【小沼さんの言葉】「記入場所はK28以降。I列に名前があるから名前の人物の希望をK列に書くイメージ。
 *   数はJ列にすでにあるから必要ない」「スタッフのみ」
 *
 * ⚠ 数え方は WishCount（希望まとめと同じ）。〇の日に案件が無ければ1日=1。
 */
class SheetWishColumnTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token-0123456789abcdef';

    /** 名簿（27行目の見出し＋28行目から名前）だけのシート。 */
    private function rows(array $names, string $header = '希望'): array
    {
        $rows = array_fill(0, 26, ['']);
        $rows[] = ['', '', '', '', '', '', '', '', '東京バイト名', '数', $header, '割合'];
        foreach ($names as $n) {
            $rows[] = ['', '', '', '', '', '', '', '', $n, '0', '', ''];
        }

        return $rows;
    }

    private function send(array $rows)
    {
        config(['ecs.sheet_sync_token' => self::TOKEN]);

        return $this->postJson('/sheet-sync', [
            'token' => self::TOKEN, 'tab' => '202611', 'office' => '東京', 'rows' => $rows,
        ]);
    }

    private function wish(string $staffId, array $days): void
    {
        foreach ($days as $d) {
            ShiftPreference::create([
                'staff_id' => $staffId, 'period' => '2026-11', 'date' => $d, 'availability' => '稼働可',
            ]);
        }
    }

    public function test_名簿の名前ごとにスタッフの希望数を返す(): void
    {
        $a = PersonFactory::new()->staff()->create(['name' => '山田 花子', 'office' => '東京']);
        $b = PersonFactory::new()->staff()->create(['name' => '佐藤太郎', 'office' => '東京']);
        PersonFactory::new()->create(['name' => '社員一郎', 'office' => '東京']);   // 社員
        $this->wish($a->id, ['2026-11-03', '2026-11-04', '2026-11-10']);

        // 名前の空白の有無は気にしない／社員・知らない名前は空欄／途中の空行もそのまま位置を保つ
        $res = $this->send($this->rows(['山田花子', '社員一郎', '', '知らない人', '佐藤 太郎']));

        $res->assertOk()
            ->assertJsonPath('wishes.row', 28)
            ->assertJsonPath('wishes.col', 11)
            ->assertJsonPath('wishes.values', ['3', '', '', '', '0']);
    }

    public function test_見出しが希望でなければ何も書かない(): void
    {
        PersonFactory::new()->staff()->create(['name' => '山田花子', 'office' => '東京']);

        $this->send($this->rows(['山田花子'], '備考'))
            ->assertOk()
            ->assertJsonPath('wishes.values', []);
    }

    public function test_同じ名前のスタッフが2人いたら空欄(): void
    {
        PersonFactory::new()->staff()->create(['name' => '山田花子', 'office' => '東京']);
        PersonFactory::new()->staff()->create(['name' => '山田 花子', 'office' => '東京']);

        $this->send($this->rows(['山田花子']))
            ->assertOk()
            ->assertJsonPath('wishes.values', ['']);
    }

    public function test_希望の列が変わっただけなら中身は変わっていない扱い(): void
    {
        PersonFactory::new()->staff()->create(['name' => '山田花子', 'office' => '東京']);
        $rows = $this->rows(['山田花子']);
        $this->send($rows)->assertOk();

        // 翌朝：GASが K列に希望数を書き、L列（割合）も変わった
        $rows[27][10] = '3';
        $rows[27][11] = '0';
        $this->send($rows)->assertOk()->assertJsonPath('changed', false);

        // 名簿の名前が変われば「変わった」
        $rows[27][8] = '佐藤太郎';
        $this->send($rows)->assertOk()->assertJsonPath('changed', true);
    }
}
