<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ公開ボードを「日付で飛べる」ようにした（2026-09-08 baba要望
 * 「公開ボードも日付で飛べるようにしてほしい」）。
 *
 * 【なぜ】左メニューの年月フォルダは「月」までしか飛べない。
 *   1か月に20件並ぶと、そこから目で探し直すことになる。
 *
 * ⚠ 見張っていること＝日付チップの置き場所と、飛ぶための印（行の data-date）が
 *   両方そろっていること。どちらか片方だけになると、押しても何も起きない画面になる。
 */
class PublishBoardDayJumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_board_has_day_chips(): void
    {
        ProjectFactory::new()->create([
            'start_date' => now()->addDays(5)->format('Y-m-d'), 'required_count' => 6, 'office' => '東京',
        ]);
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/assign-publish')
            ->assertOk()
            // 日付チップの置き場所と組み立て
            ->assertSee('id="dayChips"', false)
            ->assertSee('function buildDayChips(', false)
            ->assertSee('日付で飛ぶ：', false)
            // 押したときに飛ぶところ
            ->assertSee('function jumpToDay(', false)
            // ⚠ 行に日付の印を付けていること（これが無いと行を見つけられない）
            ->assertSee('tr.dataset.date = dayKey(c.date)', false)
            // ⚠ 表を描いたあとにチップを作り直していること（表と日がずれないため）
            ->assertSee('buildDayChips();', false);
    }

    /**
     * ⚠ 日付の印は toISOString で作らない（時差で前日になる）。
     * 実際にこの repo で日付が1日ずれる事故を何度も起こしているため、書き方を見張る。
     */
    public function test_day_key_does_not_use_iso_string(): void
    {
        $blade = file_get_contents(resource_path('views/assign_publish.blade.php'));

        $this->assertStringContainsString('function dayKey(', $blade);
        // ⚠ 探すのは「呼び出し（toISOString(）」だけ。注意書きの文章に引っかからないようにする。
        $this->assertStringNotContainsString('toISOString(', $blade,
            'toISOString は時差で日付がずれる。dayKey は年・月・日を組み立てて作ること。');
    }

    /**
     * カレンダーから日付を選んでも飛べる（2026-09-18 baba要望「日付に飛べるようにしてほしい」）。
     * ⚠ 下の日付チップは「案件がある日」しか並ばない。
     *   案件が1件も無い日や、チップが多くて探しにくいときのために、日付の入力欄を常に出しておく。
     */
    public function test_publish_board_has_a_date_picker(): void
    {
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/assign-publish')
            ->assertOk()
            ->assertSee('id="dayPick"', false)
            ->assertSee('今日へ', false)
            ->assertSee('function todayKeyStr(', false)
            // ⚠ その日に案件が無いときは黙らない（押して何も起きないと壊れて見える）。
            ->assertSee('この日の案件はありません', false);
    }

    /**
     * 「非公開にする」ボタンがある（2026-09-18 baba要望「公開する以外に非公開が欲しい」）。
     * ⚠ 前からある機能だが、ボタンの名前が「公開取消」で見つけてもらえなかった。
     *   画面の状態バッジ（非公開）と同じ言葉にそろえる。
     */
    public function test_publish_board_can_unpublish_one_by_one(): void
    {
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $this->actingAsPerson($me)->get('/assign-publish')
            ->assertOk()
            ->assertSee('非公開にする', false)
            ->assertSee('まとめて非公開', false);
    }
}
