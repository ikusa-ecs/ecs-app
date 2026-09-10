<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 集計ダッシュボード（/stats）の「所属で絞る」「社歴順で並べる」の見張り（FB No.10・No.11）。
 *
 * ⚠ ここで守りたいのは3つ。
 *   ① 所属で絞れるのは「人」の集計だけ。イベント数は案件を数えているので変わらない
 *      （変わったように見えると、社内の数字の話が食い違う）。
 *   ② 並びの既定は社歴順（入社が古い＝先輩が上）。入社年月日が空の人はいちばん下。
 *      空を「いちばん古い」と扱うと、入れていない新人が先頭に来て意味が逆になる。
 *   ③ CSVは画面と同じ条件・同じ並びで出る。
 */
class StatsFilterSortTest extends TestCase
{
    use RefreshDatabase;

    /** 集計対象になる日（今月＝画面が既定で開く期間）。 */
    private function today(): string
    {
        return Carbon::today()->format('Y-m-d');
    }

    /**
     * 3人の社員と、その出勤（アサイン）を作る。
     * 先輩(2018年入社・出勤1) / 後輩(2024年入社・出勤3) / 入社日なし(出勤2)。
     */
    private function seedMembers(): array
    {
        $project = ProjectFactory::new()->create([
            'start_date' => $this->today(), 'status' => '未着手', 'office' => '東京',
            'project_name' => 'テスト案件', 'format' => 'リアル',
        ]);

        $senior = PersonFactory::new()->create([
            'name' => 'センパイ太郎', 'office' => '東京', 'department' => 'イベプラ', 'hire_date' => '2018-04-01',
        ]);
        $junior = PersonFactory::new()->create([
            'name' => 'コウハイ次郎', 'office' => '東京', 'department' => 'イベプラ', 'hire_date' => '2024-04-01',
        ]);
        $unknown = PersonFactory::new()->create([
            'name' => 'ミテイ三郎', 'office' => '東京', 'department' => 'セールス', 'hire_date' => null,
        ]);

        $rows = [$senior->id => 1, $junior->id => 3, $unknown->id => 2];
        foreach ($rows as $staffId => $times) {
            for ($i = 0; $i < $times; $i++) {
                Assignment::create([
                    'project_id' => $project->id, 'staff_id' => $staffId,
                    'date' => Carbon::today()->addDays($i)->format('Y-m-d'),
                    'role' => 'OP', 'status' => '確定',
                ]);
            }
        }

        // 画面を開く人。所属は「その他」に入るものにして、イベプラで絞ったとき混ざらないようにする。
        $me = PersonFactory::new()->create([
            'name' => 'ミルヒト管理者', 'permission' => 'manager', 'office' => '東京',
            'department' => '経営管理', 'hire_date' => null,
        ]);

        return [$me, $project];
    }

    /** 既定の並びは社歴順＝入社が古い人が上・入社日が空の人はいちばん下。 */
    public function test_default_order_is_by_hire_date_and_blank_goes_last(): void
    {
        [$me] = $this->seedMembers();

        // ⚠ 拠点を選ぶと社員別が「部署ごとのカード」に分かれる（2026-09-10 から既定は自拠点）。
        //    ここで見たいのは並び順だけなので、全社を1枚で見る `office=all` で開く。
        $html = $this->actingAsPerson($me)->get('/stats?office=all')->assertOk()->getContent();

        $senior = strpos($html, 'センパイ太郎');
        $junior = strpos($html, 'コウハイ次郎');
        $unknown = strpos($html, 'ミテイ三郎');

        $this->assertNotFalse($senior);
        $this->assertLessThan($junior, $senior, '入社が古い人（先輩）が上に来ること');
        $this->assertLessThan($unknown, $junior, '入社年月日が空の人はいちばん下にまとめること');
    }

    /** 「出勤数が多い順」を選べば、これまでどおり出勤の多い人が上に来る。 */
    public function test_sort_by_count_still_works(): void
    {
        [$me] = $this->seedMembers();

        $html = $this->actingAsPerson($me)->get('/stats?sort=count&office=all')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'ミテイ三郎'), strpos($html, 'コウハイ次郎'), '出勤3回の人が2回の人より上');
        $this->assertLessThan(strpos($html, 'センパイ太郎'), strpos($html, 'ミテイ三郎'), '出勤2回の人が1回の人より上');
    }

    /** 所属で絞ると、その所属の人だけになる（他の所属の人は消える）。 */
    public function test_department_filter_keeps_only_that_department(): void
    {
        [$me] = $this->seedMembers();

        $html = $this->actingAsPerson($me)->get('/stats?dept=plan')->assertOk()->getContent();

        $this->assertStringContainsString('センパイ太郎', $html);
        $this->assertStringContainsString('コウハイ次郎', $html);
        $this->assertStringNotContainsString('ミテイ三郎', $html, 'セールスの人はイベプラで絞ったら出ない');
        // 社員別の見出しが「イベプラ（2名）」＝「その他」に入る人（経営管理の管理者）も混ざっていないこと。
        // ※ ログイン中の人の名前は画面の上や左メニューにも出るので、名前ではなく人数で見る。
        $this->assertStringContainsString('イベプラ（2名）', $html);
    }

    /** 所属で絞ってもイベント数は変わらない（案件を数えているため）＋その旨を画面に出す。 */
    public function test_department_filter_does_not_change_event_count(): void
    {
        [$me] = $this->seedMembers();

        $all = $this->actingAsPerson($me)->get('/stats')->assertOk()->getContent();
        $plan = $this->actingAsPerson($me)->get('/stats?dept=plan')->assertOk()->getContent();

        // KPIの「イベント数（合計）」の数字を取り出して比べる。
        $pick = function (string $html): string {
            preg_match('/イベント数（合計）.*?<div class="k-num">(\d+)/s', $html, $m);

            return $m[1] ?? '';
        };

        $this->assertSame('1', $pick($all), 'この期間のイベントは1件');
        $this->assertSame($pick($all), $pick($plan), '所属で絞ってもイベント数は変わらないこと');
        $this->assertStringContainsString('イベント数は案件ごとの集計なので、所属では変わりません', $plan);
    }

    /** 所属で絞っている間は「スタッフ別」を出さない（スタッフに所属が無く、0名と誤解されるため）。 */
    public function test_staff_block_is_hidden_while_filtering_by_department(): void
    {
        [$me] = $this->seedMembers();

        $all = $this->actingAsPerson($me)->get('/stats')->assertOk()->getContent();
        $plan = $this->actingAsPerson($me)->get('/stats?dept=plan')->assertOk()->getContent();

        $this->assertStringContainsString('スタッフ別 イベント出勤数', $all);
        $this->assertStringNotContainsString('スタッフ別 イベント出勤数', $plan);
    }

    /** CSVも画面と同じ条件・同じ並びで出る。 */
    public function test_csv_follows_the_same_filter_and_order(): void
    {
        [$me] = $this->seedMembers();

        $csv = $this->actingAsPerson($me)->get('/stats/export.csv?dept=plan')->assertOk()->getContent();

        $this->assertStringContainsString('所属,イベプラ', $csv);
        $this->assertStringContainsString('社歴順', $csv);
        $this->assertStringNotContainsString('ミテイ三郎', $csv, 'CSVも所属で絞られること');
        $this->assertStringNotContainsString('スタッフ別 イベント出勤', $csv);
        $this->assertLessThan(
            strpos($csv, 'コウハイ次郎'),
            strpos($csv, 'センパイ太郎'),
            'CSVの並びも社歴順'
        );
    }

    /** 知らない所属コードが来ても落ちない（絞らないで全部出す）。 */
    public function test_unknown_department_code_is_ignored(): void
    {
        [$me] = $this->seedMembers();

        $html = $this->actingAsPerson($me)->get('/stats?dept=なにこれ&sort=なにこれ')->assertOk()->getContent();

        $this->assertStringContainsString('ミテイ三郎', $html, '絞り込みが効かない指定は「すべて」に戻す');
    }
}
