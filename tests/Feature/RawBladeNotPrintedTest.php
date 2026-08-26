<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「Bladeの書き方が、解釈されずにそのまま画面に出ていないか」を全画面まとめて見張るテスト。
 *
 * 画面の多くは @verbatim（Bladeを解釈しない区間）でできている。その中に Blade の書き方を
 * 書いても**エラーにならず、文字がそのまま画面に出る／効かない**ので、目で見るまで気づけない。
 * 実際に何度も踏んでいる：
 *   ・2026-08-21 コメントが画面に出た／運営場所のプルダウンが空になった
 *   ・2026-08-26 公開ボードのお知らせ文の見出しに、拠点名のかわりに記号がそのまま出た
 *     （あわせて 共通設定の文字数の注記・スタッフ稼働状況の対象月 も同じ状態だった）
 *
 * ⚠ 探すのは「Bladeにしか出てこない形」だけ。
 *   `@verbatim` という字そのものは、区間の中の説明コメントに書いてあることがある（わざと）ので探さない。
 *   区間の開き閉じが合っているかは `grep -c "^@verbatim$"` と `^@endverbatim$` の数を比べれば分かる。
 */
class RawBladeNotPrintedTest extends TestCase
{
    use RefreshDatabase;

    /** 解釈されずに出たら困る書き方。 */
    private const RAW = ['{{ $', '{{--', '--}}', '{{ now(', '@foreach', '@endforeach', '@endif', '@include'];

    /** 見張る画面（Bladeの区間と混ざっているもの中心）。 */
    public static function screens(): array
    {
        return array_map(fn ($u) => [$u], [
            '/dashboard', '/projects', '/project-form', '/assign', '/assign-detail',
            '/assign-director', '/assign-publish', '/assign-sheet', '/assign-dashboard',
            '/entries', '/entry-feed', '/pickup', '/project-assign', '/projects-agg',
            '/employees', '/employee-availability', '/staff',
            '/settings', '/masters', '/imports', '/past-import', '/project-import',
            '/person-import', '/content-import', '/stats', '/paper-stock',
            '/finance-list', '/mypage', '/mypage-finance', '/project-history',
            '/account-new', '/admin-console', '/guide', '/guide-staff', '/assign-wishlist',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_screen_has_no_raw_blade_syntax(string $url): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'E-001', 'name' => 'テスト管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        // 案件を1件置く。案件が0件だと「見せかけモード」に落ちる画面や、
        // 案件を選べず一覧へ戻される画面（/project-assign）があるため。
        $project = ProjectFactory::new()->create([
            'office' => '東京', 'status' => '確定',
            'start_date' => now()->addDays(10)->format('Y-m-d'),
        ]);

        // アサイン画面は「どの案件か」を URL で受け取る（無いと一覧へ戻される）。
        if ($url === '/project-assign') {
            $url .= '?project=' . urlencode($project->id);
        }

        $html = $this->actingAsPerson($me)->get($url)->assertOk()->getContent();

        foreach (self::RAW as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "{$url} に Bladeの書き方「{$needle}」がそのまま出ています（@verbatim 区間の中に書いていないか確認）"
            );
        }
    }
}
