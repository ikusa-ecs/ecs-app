<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 日別ボード（/assign）で「その日、別の案件にもう入っている人」を一目で分かるようにする
 * （2026-09-03 baba要望「すでにアサインされてる人はもっとわかりやすく表示してほしい」）。
 *
 * ⚠ それまでの状態：
 *   ・希望者カラム …… 「✓ アサイン済み」は**この案件**に入れた人だけ。
 *     同じ日に別の案件へ入っている人は**まったく印が無かった**。
 *   ・「＋社員を追加」「＋スタッフを追加」…… 五十音順に混ざって並ぶだけ。
 *     押して確認のダイアログが出て、はじめて「別案件に入っています」と分かる＝押してから気づく。
 *
 * ⚠ 消さずに「下げて赤くする」形にしてある。事情があって重ねたいことがあるため、
 *   選べなくはしない。この見張りは**印そのものが消えていないか**を見る。
 */
class BoardBusySamedayTest extends TestCase
{
    use RefreshDatabase;

    private function emp(): Person
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false, 'active' => true,
        ]);
    }

    /** 同じ日の他案件に入っている人を調べる仕掛けがあること。 */
    public function test_the_board_knows_who_is_taken_on_the_same_day(): void
    {
        $this->actingAsPerson($this->emp())->get('/assign')
            ->assertOk()
            ->assertSee('function takenSameDayWhere', false)
            ->assertSee('function busyTitle', false);
    }

    /** 希望者カラムに「⛔」の印が出ること（案件名はマウスを乗せたときだけ＝幅を取らない）。 */
    public function test_the_applicant_column_marks_people_taken_elsewhere(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('cstat busy', $html, '希望者カラムの「別案件」の印が消えています。');
        // ⚠ 案件名は出さない（幅を取るため・2026-09-03 baba）。マウスを乗せたときだけ出す。
        $this->assertStringNotContainsString('busy-where', $html, '案件名まで出すと横幅を取ります。印だけにしてください。');
        $this->assertStringContainsString('busyTitle(p.name, where)', $html, 'マウスを乗せたときの説明が消えています。');
    }

    /** 「＋社員を追加」の一覧で、別案件の人が下にまとまり、境目の見出しが出ること。 */
    public function test_the_picker_moves_taken_people_to_the_bottom(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('return free.concat(busy);', $html, '別案件の人を下へ回す並べ替えが消えています。');
        $this->assertStringContainsString('ここから下は、この日すでに別の案件に入っている人です', $html);
        $this->assertStringContainsString('busy-tag', $html);
    }

    /**
     * 希望者カラムでも、すでに入っている人は下へ回ること（2026-09-03 baba要望）。
     * ⚠ 上から順に見れば、まだ声を掛けられる人だけになる、が狙い。
     */
    public function test_the_applicant_column_puts_taken_people_last(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('function busyRank', $html, '「下へ回す」並べ替えが消えています。');
        $this->assertStringContainsString('function sortFreeFirst', $html);
        $this->assertStringContainsString('sortFreeFirst(dp.filter(p => !p.emp))', $html);
        $this->assertStringContainsString('空き${freeCount}名', $html, '声を掛けられる人数の表示が消えています。');
    }

    /**
     * 社員は開かないと出ないこと（2026-09-03 baba「基本イベントには出ないので」）。
     * ⚠ 社員の出勤可能日もスタッフと同じ表（shift_preferences）に入るため、
     *   印（emp）が無いと見分けられない。サーバーが渡すのをやめると、また混ざる。
     */
    public function test_employees_are_folded_away_in_the_applicant_column(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('<details class="cand-emp">', $html, '社員のたたみが消えています。');
        $this->assertStringContainsString('ふだんイベントには出ません', $html);
        $this->assertStringContainsString('sortFreeFirst(dp.filter(p => p.emp))', $html);
        $this->assertStringContainsString('.cand-emp', $html, '社員のたたみの見た目（CSS）が消えています。');
    }

    /** 社員かどうかの印を、サーバーが希望者データに付けて渡していること。 */
    public function test_the_server_marks_who_is_an_employee(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/AssignBoardController.php'));

        $this->assertSame(
            2,
            substr_count($src, "'emp' => (optional(\$person)->role === 'employee')"),
            '応募者と稼働可の両方に社員の印が要ります（片方だけだと社員が混ざります）。'
        );
    }

    /** 赤くする見た目（CSS）が残っていること。色が消えると印に気づけない。 */
    public function test_the_warning_colours_are_still_there(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('.cand-row.busy', $html);
        $this->assertStringContainsString('.pick-box .pk-item.busy', $html);
        $this->assertStringContainsString('.pick-box .pk-sep', $html);
    }
}
