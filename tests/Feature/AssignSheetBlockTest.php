<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表のメンバー欄＝現場のアサイン表と同じ「NO／名前／P／巡回／備考」のブロック
 * （2026-09-28 baba要望）。
 *
 * 【決めたこと（2026-09-28 baba）】
 *   ・行数は **小型・中型＝15／大型＝30** で固定。空いている行も枠として出す。
 *     ⚠ 規模が空の案件は小型あつかい（＝15行）。
 *   ・**担当メモ（軍師/サポ等）は「巡回」の列**にまとめて出す（列を増やさない）。
 *   ・**派遣もブロックの中**に入れる＝NOを1つ使う。
 *   ・顧客は「頭」に移す。
 *   ・空の項目も隠さず「未入力」を出す（必須は赤・後で必要は黄）。
 *
 * ⚠ この画面は Blade で組み立てている（JSではない）ので、HTML を直接見て確かめられる。
 */
class AssignSheetBlockTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** その月のアサイン表を開く。 */
    private function sheet(string $date)
    {
        $ym = Carbon::parse($date)->format('Y-m');

        return $this->actingAsPerson($this->manager())->get('/assign-sheet?month='.$ym);
    }

    /** 小型・中型・規模なしは15行。行番号15があって16が無いこと。 */
    public function test_ブロックは15行(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型']);

        $html = $this->sheet($date)->assertOk()->getContent();

        // ⚠ 見出しにも c-no があるので、行のほうを数える（class="mrow mblk…"）。
        $this->assertSame(15, substr_count($html, 'class="mrow mblk'), 'ブロックの行が15行ちょうどであること');
    }

    /** 大型は30行。 */
    public function test_大型は30行(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '大型']);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertSame(30, substr_count($html, 'class="mrow mblk'), '大型は30行');
    }

    /** 規模が空なら15行（小型あつかい）。 */
    public function test_規模が空なら15行(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => null]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertSame(15, substr_count($html, 'class="mrow mblk'));
    }

    /** 列の見出しが5つそろっていること（現場のアサイン表と同じ並び）。 */
    public function test_列の見出し(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('<span class="c-no">NO</span>', $html);
        $this->assertStringContainsString('<span class="c-nm">名前</span>', $html);
        $this->assertStringContainsString('<span class="c-p">P</span>', $html);
        $this->assertStringContainsString('<span class="c-jn">巡回</span>', $html);
        $this->assertStringContainsString('<span class="c-rm">備考</span>', $html);
    }

    /** 担当メモは「巡回」の列に出す（baba指定）。名前の列には出さない。 */
    public function test_担当メモは巡回の列に出る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);
        $staff = PersonFactory::new()->staff()->create(['office' => '東京', 'name' => '巡回テスト太郎']);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $staff->id, 'date' => $date,
            'role' => 'OP', 'status' => '確定', 'note' => '軍師', 'patrol' => 3,
        ]);

        $html = $this->sheet($date)->assertOk()->getContent();

        // 巡回の列（c-jn）の中に「軍師」と「巡3」が入っていること。
        $this->assertMatchesRegularExpression(
            '/<span class="c-jn">.*?軍師.*?巡3.*?<\/span>/s',
            $html,
            '担当メモと巡回数は「巡回」の列にまとめて出す'
        );
    }

    /** 派遣もブロックの中に入り、NOを1つ使う（＝空き行が1つ減る）。 */
    public function test_派遣もブロックの中に入る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date, 'scale' => '中型']);
        \App\Models\ProjectDispatch::create([
            'project_id' => $p->id, 'agency' => 'テスト派遣会社', 'count' => 2,
            'status' => '依頼中', 'requested_on' => $date,
        ]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('テスト派遣会社', $html);
        // 行の合計は15のまま（派遣が1行ぶんを使い、空き行が1つ減る）。
        $this->assertSame(15, substr_count($html, 'class="mrow mblk'));
    }

    /** 空の項目は隠さず「未入力」を出す。必須の段階で色が変わる。 */
    public function test_空の項目は未入力と出る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        // 会場住所（黄＝後で必要）を空にする。
        ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'location' => null,
        ]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('未入力', $html);
        $this->assertStringContainsString('class="unfilled later"', $html, '後で必要な項目は黄色で出す');
    }

    /** 顧客は「頭」（sticky）の中にある。 */
    public function test_顧客は頭にある(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'client' => 'カブシキガイシャ見本',
        ]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $sticky = explode('/acard-sticky', $html)[0];
        $this->assertStringContainsString('カブシキガイシャ見本', $sticky, '顧客は頭（上に貼り付く部分）に出す');
    }

    /** 集合〜解散に拘束時間が出る。 */
    public function test_拘束時間が出る(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => $date, 'start_time' => '9:00', 'end_time' => '18:30',
        ]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringContainsString('拘束 9時間30分', $html);
    }

    /** 担当内訳は出さない（baba「必要ないです」）。 */
    public function test_担当内訳は出さない(): void
    {
        $date = Carbon::today()->addDays(5)->format('Y-m-d');
        ProjectFactory::new()->create(['office' => '東京', 'start_date' => $date]);

        $html = $this->sheet($date)->assertOk()->getContent();

        $this->assertStringNotContainsString('担当内訳', $html);
    }
}
