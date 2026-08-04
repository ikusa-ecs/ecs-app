<?php

namespace Tests\Feature;

use App\Models\Content;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * よく使う画面が「エラーにならず開けること」を確かめる土台のテスト（スモークテスト）。
 *
 * 画面の中の動き（クリックしたら開く等）はブラウザでの確認になるが、
 * 画面が壊れて500エラーになる事故はここで止められる。
 * 本番運用に入ると「開かない」が一番困るため、主要画面は必ずここに並べておく。
 */
class ScreenRenderTest extends TestCase
{
    use RefreshDatabase;

    /** 社員が開ける主要画面は、どれもエラーにならず表示される。 */
    public function test_main_screens_open_without_error(): void
    {
        $employee = PersonFactory::new()->create();

        foreach (['/dashboard', '/projects', '/project-form'] as $url) {
            $this->actingAsPerson($employee)->get($url)
                ->assertOk("画面 {$url} が開けませんでした");
        }
    }

    /**
     * 案件登録フォームの「案件名（コンテンツ）」の候補は、コンテンツ台帳から出る。
     *
     * 以前は画面に12件をベタ書きしていて、台帳に登録したコンテンツが候補に出なかった
     * （社内フィードバックで指摘あり）。台帳を見に行くようになったことを確認する。
     */
    public function test_project_form_offers_content_names_from_the_master(): void
    {
        Content::create(['id' => 'C-901', 'content_name' => 'テスト用コンテンツ甲', 'active' => true]);
        Content::create(['id' => 'C-902', 'content_name' => 'テスト用コンテンツ乙', 'active' => false]);

        $res = $this->actingAsPerson(PersonFactory::new()->create())->get('/project-form');

        $res->assertOk();

        // 画面のHTMLでは日本語が テ… の形に変換されて入るため、文字そのものではなく
        // 画面へ渡している候補の中身（contentOptions）で確かめる。
        $options = $res->original->getData()['contentOptions'];

        // 有効なコンテンツは候補に出る。使わなくなった（active=false）ものは出さない。
        $this->assertContains('テスト用コンテンツ甲', $options);
        $this->assertNotContains('テスト用コンテンツ乙', $options);
    }
}
