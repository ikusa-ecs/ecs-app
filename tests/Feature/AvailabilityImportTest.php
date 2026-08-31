<?php

namespace Tests\Feature;

use App\Models\ShiftPreference;
use App\Support\AvailabilitySheetReader;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 社員の出勤可能日のまとめて取込（2026-08-31 baba要望）。
 *
 * 見張るところ：
 *  1. 実物のシートの形（見出し「項目」＋「9/6」などの日付＋平日希望休＋備考）が読める
 *  2. 記号のゆれ（〇○◯／×✕✗✖／△▲）を全部読む
 *  3. **予定名が書いてあるマスは △ にして、その文字をその日のメモに残す**（baba決定）
 *  4. **年月は人が選ぶ**＝合わない月の見出しは読まない（勘で年を決めない）
 *  5. **本人がすでに入れた日は上書きしない**（既定）
 *  6. 名簿に見つからない名前は入れずに知らせる
 */
class AvailabilityImportTest extends TestCase
{
    use RefreshDatabase;

    /** 実物のシートと同じ形（タブ区切りの貼り付け）。 */
    private function sheet(): string
    {
        return implode("\n", [
            "\t\t土\t日\t土\t日\t祝\t\t",
            "\t項目\t9/6\t9/7\t9/13\t9/14\t9/15\t平日希望休日\t参加したいイベント、参加したいイベント数、その他備考",
            "\t中村 淳司\t〇\t〇\t〇\t✗\t○\t\t",
            "\t小田 紅\t〇\t〇\tアイシン配信\t△\t×\t9/29,30\t大型ないので小型大量に入ります",
            "\t居ない 人\t〇\t〇\t〇\t〇\t〇\t\t",
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'period' => '2026-09',
            'pasted' => $this->sheet(),
        ], $over);
    }

    /** 見出しと記号のゆれを読み、予定名はメモに残して△にする。 */
    public function test_it_reads_the_real_sheet_shape(): void
    {
        $rows = \App\Support\CsvText::rowsPasted($this->sheet());
        $read = AvailabilitySheetReader::read($rows, '2026-09');

        $this->assertSame(
            ['2026-09-06', '2026-09-07', '2026-09-13', '2026-09-14', '2026-09-15'],
            $read['dates']
        );

        $oda = collect($read['people'])->firstWhere('name', '小田 紅');
        $this->assertNotNull($oda);
        // 記号のゆれ
        $this->assertSame('ok', $oda['days']['2026-09-06']['code']);
        $this->assertSame('maybe', $oda['days']['2026-09-14']['code']);
        $this->assertSame('ng', $oda['days']['2026-09-15']['code']);
        // 予定名＝△＋メモ（勝手に×にしない）
        $this->assertSame('maybe', $oda['days']['2026-09-13']['code']);
        $this->assertSame('アイシン配信', $oda['days']['2026-09-13']['memo']);
        // 平日希望休「9/29,30」＝同じ月の30日も読む
        $this->assertSame(['2026-09-29', '2026-09-30'], $oda['offDays']);
        $this->assertSame('大型ないので小型大量に入ります', $oda['note']);

        // 別の書き方の×（✗）と○も読める
        $naka = collect($read['people'])->firstWhere('name', '中村 淳司');
        $this->assertSame('ng', $naka['days']['2026-09-14']['code']);
        $this->assertSame('ok', $naka['days']['2026-09-15']['code']);
    }

    /**
     * 選んだ年月と合わない見出しは読まない。
     * ⚠ ここが緩いと、9月のシートを10月として取り込んで**去年や別の月に入る**。
     */
    public function test_headers_from_another_month_are_not_read(): void
    {
        $rows = \App\Support\CsvText::rowsPasted($this->sheet());
        $read = AvailabilitySheetReader::read($rows, '2026-11');

        $this->assertSame([], $read['dates']);
        $this->assertNotEmpty($read['errors']);
    }

    /** 読めない希望休の文は、勝手に日付にせず備考へ回す。 */
    public function test_unreadable_day_off_text_goes_to_the_note(): void
    {
        $sheet = implode("\n", [
            "\t項目\t9/6\t9/7\t9/13\t平日希望休日\t備考",
            "\t中村 淳司\t〇\t〇\t〇\t4日が大型なので前後厳しいです…\t",
        ]);
        $read = AvailabilitySheetReader::read(\App\Support\CsvText::rowsPasted($sheet), '2026-09');

        $p = $read['people'][0];
        $this->assertSame([], $p['offDays']);
        $this->assertStringContainsString('4日が大型なので', $p['note']);
    }

    /** プレビューに、入る内容と「名簿に無い人」が出る。 */
    public function test_the_preview_shows_what_will_be_saved(): void
    {
        $me = PersonFactory::new()->manager()->create();
        PersonFactory::new()->create(['name' => '中村 淳司']);
        PersonFactory::new()->create(['name' => '小田 紅']);

        $res = $this->actingAsPerson($me)
            ->post('/availability-import/preview', $this->payload())
            ->assertOk();

        $plan = $res->viewData('preview');
        $this->assertCount(2, $plan['rows']);
        $this->assertSame(['居ない 人'], $plan['missing']);
    }

    /** 保存すると shift_preferences に入る。 */
    public function test_it_saves_to_shift_preferences(): void
    {
        $me = PersonFactory::new()->manager()->create();
        $oda = PersonFactory::new()->create(['name' => '小田 紅']);

        $this->actingAsPerson($me)->post('/availability-import', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('shift_preferences', [
            'staff_id' => $oda->id, 'availability' => '稼働可', 'period' => '2026-09',
        ]);
        // 予定名はその日のメモに残る
        $row = ShiftPreference::where('staff_id', $oda->id)->whereDate('date', '2026-09-13')->first();
        $this->assertSame('未定', $row->availability);
        $this->assertSame('アイシン配信', $row->day_note);
        // 平日希望休
        $off = ShiftPreference::where('staff_id', $oda->id)->whereDate('date', '2026-09-29')->first();
        $this->assertSame('希望休', $off->availability);
    }

    /**
     * 本人がすでに入れた日は上書きしない（既定）。
     * ⚠ ここが緩いと、本人が自分で入れた予定を取込が消してしまう。
     */
    public function test_it_keeps_what_the_person_already_entered(): void
    {
        $me = PersonFactory::new()->manager()->create();
        $naka = PersonFactory::new()->create(['name' => '中村 淳司']);
        ShiftPreference::create([
            'staff_id' => $naka->id, 'period' => '2026-09',
            'date' => '2026-09-06', 'availability' => 'NG',
        ]);

        $this->actingAsPerson($me)->post('/availability-import', $this->payload())->assertRedirect();

        $row = ShiftPreference::where('staff_id', $naka->id)->whereDate('date', '2026-09-06')->first();
        $this->assertSame('NG', $row->availability, '本人の入力がシートで上書きされてしまいました');
        // 入っていなかった日はちゃんと入る
        $this->assertNotNull(ShiftPreference::where('staff_id', $naka->id)->whereDate('date', '2026-09-07')->first());
    }

    /** 「全部上書き」にチェックしたときは書き換える。 */
    public function test_overwrite_replaces_what_the_person_entered(): void
    {
        $me = PersonFactory::new()->manager()->create();
        $naka = PersonFactory::new()->create(['name' => '中村 淳司']);
        ShiftPreference::create([
            'staff_id' => $naka->id, 'period' => '2026-09',
            'date' => '2026-09-06', 'availability' => 'NG',
        ]);

        $this->actingAsPerson($me)
            ->post('/availability-import', $this->payload(['overwrite' => '1']))
            ->assertRedirect();

        $row = ShiftPreference::where('staff_id', $naka->id)->whereDate('date', '2026-09-06')->first();
        $this->assertSame('稼働可', $row->availability);
    }

    /** 社員以外（スタッフ）はこの画面に入れない。 */
    public function test_staff_cannot_open_the_screen(): void
    {
        $staff = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->get('/availability-import')->assertRedirect();
    }
}
