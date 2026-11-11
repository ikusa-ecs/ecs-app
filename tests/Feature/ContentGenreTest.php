<?php

namespace Tests\Feature;

use App\Models\Content;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 台帳のコンテンツを種類（リアル／オンライン）→分類（スプシの分類）ごとにまとめて並べる（2026-11-11 小沼さん）。
 * ⚠ もとの「分類」（盛り上げ系・真面目系）は画面から外したが、保存で消さない。
 */
class ContentGenreTest extends TestCase
{
    use RefreshDatabase;

    private function admin()
    {
        return PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);
    }

    public function test_grouped_in_sheet_order_and_saved(): void
    {
        Content::create(['id' => 'CT-A', 'content_name' => 'チャンバラ', 'kind' => 'リアル', 'genre' => '合戦系', 'category' => '盛り上げ系', 'sort_order' => 1]);
        Content::create(['id' => 'CT-B', 'content_name' => '会議室', 'kind' => 'リアル', 'genre' => '謎解き脱出', 'sort_order' => 2]);
        Content::create(['id' => 'CT-C', 'content_name' => '電脳都市', 'kind' => 'オンライン', 'genre' => 'リモ謎', 'sort_order' => 3]);
        Content::create(['id' => 'CT-D', 'content_name' => '式典運営', 'sort_order' => 4]);
        $me = $this->admin();

        $html = $this->actingAsPerson($me)->get('/masters')->assertOk()->getContent();
        $pos = fn ($s) => mb_strpos($html, $s);
        $this->assertTrue($pos('リアル › 謎解き脱出') < $pos('リアル › 合戦系'), 'スプシの順（謎解き脱出が先）');
        $this->assertTrue($pos('リアル › 合戦系') < $pos('オンライン › リモ謎'));
        $this->assertTrue($pos('オンライン › リモ謎') < $pos('未分類'));

        // 画面の「すべて保存」：種類・分類が直る／盛り上げ系は消えない
        $this->actingAsPerson($me)->post('/masters/contents/bulk', ['rows' => [
            'CT-A' => ['content_name' => 'チャンバラ', 'kind' => 'リアル', 'genre' => '合戦系', 'active' => 1],
            'CT-D' => ['content_name' => '式典運営', 'kind' => 'リアル', 'genre' => 'ビジネスパーティー', 'active' => 1],
        ]])->assertRedirect();
        $this->assertSame('盛り上げ系', Content::find('CT-A')->category);
        $this->assertSame(['リアル', 'ビジネスパーティー'], [Content::find('CT-D')->kind, Content::find('CT-D')->genre]);
    }

    public function test_initial_fill_only_when_name_matches(): void
    {
        // マイグレーションと同じ決まり：IDと名前が両方合うときだけ入れる
        $map = \App\Support\ContentGenre::INITIAL;
        $this->assertSame(['会議室', 'リアル', '謎解き脱出'], $map['CT-001']);
        $this->assertSame(['【オンライン】電脳都市', 'オンライン', 'リモ謎'], $map['CT-063']);
        foreach ($map as [$name, $kind, $genre]) {
            $this->assertContains($genre, \App\Support\ContentGenre::GENRES[$kind], $name.' の分類が並び順に無い');
        }
    }
}
