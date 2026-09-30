<?php

namespace Tests\Feature;

use App\Support\RecruitAgainText;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードの「📣 再募集の文章」（2026-09-28 baba要望）。
 *
 * 見張るのは3つ。
 *   ① カードが1件ぶんの文章（日付・コンテンツ・会社名様・時間・場所）を持っていること
 *   ② 見出しと締め（スタッフ画面のURL）が画面に渡っていること
 *   ③ 画面の詰め替えに recruitText が残っていること（忘れると文章が空になる＝この画面でよくある事故）
 */
class BoardRecruitAgainTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->manager()->create(['office' => '東京']);
    }

    private function project(array $attrs = [])
    {
        return ProjectFactory::new()->published()->create(array_merge([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
            'client' => '東京水道株式会社',
            'content_names' => ['謎パ'],
            'start_time' => '7:30',
            'end_time' => '17:00',
            'location' => '東京都品川区1-2-3',
            'lodging' => null,
            'format' => 'リアル',
        ], $attrs));
    }

    public function test_the_card_carries_the_recruit_text(): void
    {
        $p = $this->project(['staff_meet_time' => '8:00']);

        $data = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->original->getData();
        $card = collect($data['boardCases'])->firstWhere('id', $p->id);

        $this->assertNotNull($card, '日別ボードにこの案件が出ていません');
        $text = $card['recruitText'];
        $this->assertStringContainsString('東京', $text);
        $this->assertStringContainsString('謎パ', $text);
        // 会社名は載せてよい・様を付ける（2026-09-28 baba）。
        $this->assertStringContainsString('東京水道株式会社様', $text);
        // スタッフ向けの集合時間があればそちら。
        $this->assertStringContainsString('集合 8:00 〜 解散 17:00', $text);
        $this->assertStringContainsString('場所：東京都品川区1-2-3', $text);
        // 宿泊なしは行を増やさない。
        $this->assertStringNotContainsString('宿泊', $text);

        $this->assertSame(RecruitAgainText::HEADER, $data['recruitHeader']);
        $this->assertStringContainsString('/staff-portal', $data['recruitFooter']);
    }

    public function test_online_and_lodging(): void
    {
        $p = $this->project(['format' => 'オンライン', 'lodging' => '前泊有']);

        $text = RecruitAgainText::block($p);
        $this->assertStringContainsString('場所：オンライン', $text);
        $this->assertStringContainsString('宿泊：前泊有', $text);
    }

    public function test_the_board_view_keeps_recruit_text_in_its_mapping(): void
    {
        $this->project();
        $html = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('recruitText:c.recruitText', $html,
            '画面の詰め替えから recruitText が消えると、「📣 再募集の文章」が空になります');
        $this->assertStringContainsString('📣 再募集の文章', $html);
        $this->assertStringContainsString('window.ECS_RECRUIT_HEADER', $html);
    }

    /** 状態の絞り込みに「まだ足りない募集中のみ」があり、再募集の文章と同じ条件を使う（2026-09-30 baba要望）。 */
    public function test_state_filter_has_short_recruiting(): void
    {
        $this->project();
        $html = $this->actingAsPerson($this->manager())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('<option value="short">', $html);
        $this->assertStringContainsString("if (sf === 'short') return isShortRecruiting(c);", $html);
        $this->assertStringContainsString('.filter(c => isShortRecruiting(c))', $html, '再募集の文章も同じ判定を使う');
    }
}
