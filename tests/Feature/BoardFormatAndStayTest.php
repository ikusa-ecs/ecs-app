<?php

namespace Tests\Feature;

use App\Support\Lodging;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボードのカードで「実施形態」と「前泊」が分かる（2026-09-18 baba要望
 * 「日別ボードでもリアルイベントかロングかオンラインか前泊有かわかるようにしてほしい」）。
 *
 * 見つかった原因は2つ。
 *  ① ⚠ **詰め替え漏れ**。サーバーは format / fmtCls を渡していたのに、画面がカードを
 *     作り直すところに書き写していなかった＝実施形態バッジがずっと空だった。
 *  ② ⚠ 前泊の判定が「前泊」という文字を探すだけで、**「前後泊あり」が前泊なし扱い**だった。
 *     同じ判定が3か所にコピーされていたので、正本＝App\Support\Lodging にまとめた。
 */
class BoardFormatAndStayTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(array $attrs)
    {
        return ProjectFactory::new()->published()->create(array_merge([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(4)->format('Y-m-d'),
        ], $attrs));
    }

    private function card($me, string $id): ?array
    {
        return collect(
            $this->actingAsPerson($me)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->firstWhere('id', $id);
    }

    /** 実施形態がカードに渡っている（色分けのコードも一緒に）。 */
    public function test_the_card_carries_the_format(): void
    {
        $me = $this->emp();

        foreach ([
            'リアル' => 'fmt-real',
            'リアルロング' => 'fmt-long',
            'オンライン' => 'fmt-online',
        ] as $format => $cls) {
            $p = $this->project(['format' => $format]);
            $card = $this->card($me, $p->id);

            $this->assertSame($format, $card['format'] ?? null, $format.'が渡っていません');
            $this->assertSame($cls, $card['fmtCls'] ?? null, $format.'の色分けが合いません');
        }
    }

    /**
     * ⚠ いちばんの原因。画面はサーバーから来たカードを**作り直している**ので、
     * 作り直すところに書き写さないと、渡していても画面には出ない。
     */
    public function test_the_screen_keeps_the_format(): void
    {
        $html = $this->actingAsPerson($this->emp())->get('/assign')->assertOk()->getContent();

        $this->assertStringContainsString('format:c.format', $html, '詰め替え（これが無いと出ない）');
        $this->assertStringContainsString('fmtBadgeHtml(c)', $html, 'カードに実施形態を描くところ');
        // ⚠ 置き場所＝コンテンツ名の横（2026-09-18 baba「前泊とかリアルとかはコンテンツの横に表示で」）。
        //   カードの下に離して置くと、横に並んだカードを目で追うときに見落とす。
        $this->assertStringContainsString(
            'titleBlockHtml(c, scaleBadgeHtml(c) + fmtBadgeHtml(c) + shareTagsHtml(c) + tagHtml)',
            $html,
            '実施形態と札がコンテンツ名の横に出ていません'
        );
    }

    /** 前泊ありの案件には「前泊」の札が付く。 */
    public function test_pre_stay_tag(): void
    {
        $me = $this->emp();

        foreach (['前泊有', '一部前泊有', '前後泊あり'] as $lodging) {
            $p = $this->project(['lodging' => $lodging]);

            $this->assertContains(
                '前泊',
                $this->card($me, $p->id)['tags'] ?? [],
                $lodging.'に前泊の札が付いていません'
            );
        }
    }

    /** 前泊でないものには付けない。 */
    public function test_no_tag_without_pre_stay(): void
    {
        $me = $this->emp();

        foreach (['無', '後泊あり', '', null] as $lodging) {
            $p = $this->project(['lodging' => $lodging]);

            $this->assertNotContains(
                '前泊',
                $this->card($me, $p->id)['tags'] ?? [],
                '前泊でないのに札が付いています'
            );
        }
    }

    /**
     * ⚠ 判定の正本。「前後泊あり」を前泊として数えること。
     * ここが1か所なので、日別ボード・LINEの概要文・案件登録の前泊集合欄が同時に直る。
     */
    public function test_lodging_is_the_single_source(): void
    {
        $this->assertTrue(Lodging::hasPreStay('前泊有'));
        $this->assertTrue(Lodging::hasPreStay('一部前泊有'));
        $this->assertTrue(Lodging::hasPreStay('前後泊あり'), '「前後泊あり」が前泊として数えられていません');
        $this->assertFalse(Lodging::hasPreStay('後泊あり'));
        $this->assertFalse(Lodging::hasPreStay('無'));
        $this->assertFalse(Lodging::hasPreStay(null));
        $this->assertSame('無', Lodging::label(''));
        $this->assertSame('前泊有', Lodging::label('前泊有'));
    }

    /** LINEの概要文でも「前後泊あり」で前泊集合の行が出る（同じ正本を見ている）。 */
    public function test_line_text_shows_pre_stay_for_both_stays(): void
    {
        $p = $this->project(['lodging' => '前後泊あり', 'stay_pre_meet_time' => '6:00']);

        $text = \App\Support\LineGroupText::summary($p);

        $this->assertStringContainsString('前泊集合', $text, '「前後泊あり」で前泊集合の行が出ていません');
        $this->assertStringContainsString('6:00', $text);
    }

    /**
     * 案件規模が大型だと分かる（2026-09-18 baba要望「日別ボードで大型もわかるようにしてほしい」）。
     * ⚠ scale も**詰め替えが漏れていた**。カードに札が出ないだけでなく、
     *   MCの規模上限の判定（画面の scaleOf）も効いていなかった。
     */
    public function test_scale_reaches_the_card(): void
    {
        $me = $this->emp();
        $big = $this->project(['scale' => '大型']);
        $mid = $this->project(['scale' => '中型']);

        $this->assertSame('大型', $this->card($me, $big->id)['scale'] ?? null);
        $this->assertSame('中型', $this->card($me, $mid->id)['scale'] ?? null);

        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();
        $this->assertStringContainsString('scale:c.scale', $html, '詰め替え（これが無いと出ない）');
        $this->assertStringContainsString('scaleBadgeHtml(c)', $html, 'カードに大型の札を描くところ');
    }

    /**
     * 他拠点との関わり（ヘルプ／巻き取り）が分かる
     * （2026-09-18 baba「他拠点からの巻き取りとかヘルプのときはそれもわかるようにしてほしい」）。
     */
    public function test_share_tags_reach_the_card(): void
    {
        $me = $this->emp();   // 東京の社員

        // ① 名古屋の案件を、東京が手伝っている → 「名古屋からヘルプ」
        $fromOther = $this->project(['office' => '名古屋']);
        \App\Models\ProjectShare::create([
            'project_id' => $fromOther->id, 'office' => '東京', 'kind' => 'ヘルプ',
        ]);

        // ② 東京の案件を、名古屋に手伝ってもらっている → 「名古屋にヘルプ」
        $toOther = $this->project(['office' => '東京']);
        \App\Models\ProjectShare::create([
            'project_id' => $toOther->id, 'office' => '名古屋', 'kind' => 'ヘルプ',
        ]);

        $this->assertSame(
            [['label' => '名古屋からヘルプ', 'kind' => 'ヘルプ']],
            $this->card($me, $fromOther->id)['shareTags'] ?? null
        );
        $this->assertSame(
            [['label' => '名古屋にヘルプ', 'kind' => 'ヘルプ']],
            $this->card($me, $toOther->id)['shareTags'] ?? null
        );

        // 関わりの無い案件には札を出さない。
        $plain = $this->project([]);
        $this->assertSame([], $this->card($me, $plain->id)['shareTags'] ?? null);

        $html = $this->actingAsPerson($me)->get('/assign')->assertOk()->getContent();
        $this->assertStringContainsString('shareTags:(c.shareTags || [])', $html, '詰め替え（これが無いと出ない）');
        $this->assertStringContainsString('shareTagsHtml(c)', $html, 'カードに関わりの札を描くところ');
    }

    /** 他拠点の案件を「巻き取った」ときも分かる（引き取った側のボードには出る）。 */
    public function test_taken_over_from_another_office(): void
    {
        $me = $this->emp();
        $p = $this->project(['office' => '名古屋']);
        \App\Models\ProjectShare::create([
            'project_id' => $p->id, 'office' => '東京', 'kind' => '巻き取り',
        ]);

        $this->assertSame(
            [['label' => '名古屋から巻き取り', 'kind' => '巻き取り']],
            $this->card($me, $p->id)['shareTags'] ?? null
        );
    }
}
