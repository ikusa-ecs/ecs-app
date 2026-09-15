<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「表示する拠点」のボタンが、拠点で見分ける画面すべてに付いていること。
 *
 * ⚠ 2026-09-15 baba指摘＝6つの画面だけ切替が無く、
 *   管理者でも自分の拠点に固定されたまま（URLに手で ?office=all と書くしかなかった）。
 *   画面を増やしたときに付け忘れると同じことが起きるので、ここで見張る。
 *
 * ⚠ 切替ボタンは管理者・Administrator にだけ出る（一般社員・スタッフは自拠点で固定）。
 *   これは以前からの決まりで、この見張りでは変えていない。
 */
class OfficeSwitchCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** 拠点の切替が要る画面。⚠ 拠点で見分ける画面を足したら、ここにも1行足す。 */
    private const SCREENS = [
        '/projects',            // 案件一覧
        '/assign',              // 日別ボード
        '/assign-sheet',        // アサイン表
        '/assign-dashboard',    // アサインダッシュボード（2026-09-15 に追加）
        '/dispatch-list',       // 派遣一覧（2026-09-15 に追加）
        '/experience',          // 経験回数（2026-09-15 に追加）
        '/assign-wishlist',     // スタッフ集計＝希望まとめ（2026-09-15 に追加）
        '/paper-stock',         // 謎解きの紙 在庫（2026-09-15 に追加）
    ];

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    public function test_拠点の切替ボタンが出ている(): void
    {
        $me = $this->admin();

        foreach (self::SCREENS as $url) {
            $this->actingAsPerson($me)->get($url)
                ->assertOk()
                ->assertSee('表示する拠点', false, "{$url} に拠点の切替ボタンがありません");
        }
    }

    /**
     * 2026-09-15 に足した5画面は、絞った拠点を officeScope という名前で画面に渡す。
     * ⚠ 前からある画面（案件一覧・日別ボード・アサイン表）は渡し方の名前が違うので、ここでは見ない。
     */
    private const NEW_SCREENS = [
        '/assign-dashboard',
        '/dispatch-list',
        '/experience',
        '/assign-wishlist',
        '/paper-stock',
    ];

    public function test_全拠点はoffice_allで指定する(): void
    {
        $me = $this->admin();

        foreach (self::NEW_SCREENS as $url) {
            // ⚠ 「全拠点」は空文字にしない（リンクを組み立てるときに消えて自拠点に戻るため）。
            $this->actingAsPerson($me)->get($url.'?office=all')
                ->assertOk()
                ->assertViewHas('officeScope', null, "{$url} が ?office=all を見ていません");
        }
    }

    public function test_はじめは自分の拠点で開く(): void
    {
        $me = $this->admin();

        foreach (self::NEW_SCREENS as $url) {
            $this->actingAsPerson($me)->get($url)
                ->assertOk()
                ->assertViewHas('officeScope', '東京', "{$url} が自分の拠点で開いていません");
        }
    }

    /**
     * 紙の在庫だけは特別＝入庫数を拠点で分けて持っていない。
     * 拠点を選んでいるあいだは在庫の列を出さない（「東京の在庫」という数字は存在しないため）。
     */
    public function test_紙の在庫は拠点を選ぶと在庫の列を出さない(): void
    {
        $me = $this->admin();
        // 紙が必要なコンテンツが1つも無いと表そのものが出ないので、1つ用意する。
        Content::create(['id' => 'CT-PAPER', 'content_name' => '謎解きテスト', 'needs_paper' => true]);

        $this->actingAsPerson($me)->get('/paper-stock')
            ->assertOk()
            ->assertViewHas('showStockCols', false)
            ->assertDontSee('入庫数を保存');

        $this->actingAsPerson($me)->get('/paper-stock?office=all')
            ->assertOk()
            ->assertViewHas('showStockCols', true)
            ->assertSee('入庫数を保存');
    }
}
