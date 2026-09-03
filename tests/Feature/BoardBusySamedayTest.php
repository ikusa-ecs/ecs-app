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

    /** 希望者カラムに「⛔ 別案件」と、入っている案件名が出ること。 */
    public function test_the_applicant_column_marks_people_taken_elsewhere(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('cstat busy', $html, '希望者カラムの「別案件」バッジが消えています。');
        $this->assertStringContainsString('busy-where', $html, 'どの案件に入っているかの表示が消えています。');
        $this->assertStringContainsString('うち${busyCount}名は別案件', $html, '見出しの「うち◯名は別案件」が消えています。');
    }

    /** 「＋社員を追加」の一覧で、別案件の人が下にまとまり、境目の見出しが出ること。 */
    public function test_the_picker_moves_taken_people_to_the_bottom(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('return free.concat(busy);', $html, '別案件の人を下へ回す並べ替えが消えています。');
        $this->assertStringContainsString('ここから下は、この日すでに別の案件に入っている人です', $html);
        $this->assertStringContainsString('busy-tag', $html);
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
