<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 案件登録画面が「ちゃんと組み上がっているか」を見張るテスト。
 *
 * この画面は大部分が @verbatim（Bladeを解釈しない区間）で、そこに Blade の書き方をすると
 * 画面が壊れずに“そのまま文字が出る”ため、目で見るまで気づけない。2026-08-21 に2回踏んだ：
 *   ① {{-- コメント --}} が画面に表示された
 *   ② @foreach で作った運営場所のプルダウンが空になった（選択肢が1つも出ない）
 * どちらもエラーにならないので、ここで機械的に見張る。
 */
class ProjectFormRenderTest extends TestCase
{
    use RefreshDatabase;

    private function open()
    {
        return $this->actingAsPerson(PersonFactory::new()->create(['office' => '東京']))
            ->get('/project-form');
    }

    /** Blade の書き方が、解釈されずにそのまま画面へ出ていないこと。 */
    public function test_no_raw_blade_syntax_is_printed(): void
    {
        $html = $this->open()->assertOk()->getContent();

        foreach (['{{--', '--}}', '@foreach', '@endforeach', '@if (', '@endif', '@php'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "Bladeの書き方「{$needle}」がそのまま画面に出ています（@verbatim 区間の中に書いていないか確認）"
            );
        }
    }

    /** 運営場所の選択肢が画面に届いていること（拠点マスタから作った「○○依頼」を含む）。 */
    public function test_operation_place_options_are_passed_to_the_page(): void
    {
        \App\Models\Office::firstOrCreate(['name' => '北海道'], ['sort_order' => 60, 'active' => true]);

        $html = $this->open()->assertOk()->getContent();

        $this->assertStringContainsString('ECS_OPERATION_PLACES', $html);

        // @json は日本語を \uXXXX に変換して書き出すので、その形でも探す。
        $contains = function (string $word) use ($html) {
            return str_contains($html, $word)
                || str_contains($html, trim(json_encode($word), '"'));
        };

        $this->assertTrue($contains('現地'), '「現地」が選択肢にあること');
        $this->assertTrue($contains('北海道依頼'), '拠点マスタの拠点が「○○依頼」として選べること');
    }
}
