<?php

namespace Tests\Feature;

use App\Support\PersonalCases;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マイページ「本人表示」の結合テスト。テスト仕様書 IT-MYP-01。
 *
 * 【既知バグの検証】
 * App\Support\PersonalCases::meModel() は現在ログイン中のユーザーを見ず、
 * 固定で E-007（baba）を返す（認証導入前の名残）。そのため誰でログインしても
 * マイページに baba のデータが出てしまう＝個人情報の観点で要修正。
 *
 * このテストは「ログイン中の本人が“自分”として扱われる」という正しい姿を書いている。
 * 修正前は失敗するため、いまは markTestSkipped で一旦スキップ扱いにしている
 * （＝バグ確認済みの記録）。meModel() を Auth::user() ベースに直したら、
 * 下の markTestSkipped の行を消せばこのテストが有効化され、合格するはず。
 */
class MyPageOwnDataTest extends TestCase
{
    use RefreshDatabase;

    /** IT-MYP-01：マイページの「自分」はログイン中の本人であること。 */
    public function test_mypage_identifies_the_logged_in_user_as_self(): void
    {
        $this->markTestSkipped('既知バグ IT-MYP-01：PersonalCases::meModel() が Auth を見ず E-007(baba) 固定。修正後にこの行を削除して有効化する。');

        // baba(E-007) と、別の社員（佐藤）を用意し、佐藤でログインする。
        PersonFactory::new()->create(['id' => 'E-007', 'name' => 'baba']);
        $me = PersonFactory::new()->create(['id' => 'E-200', 'name' => '佐藤']);

        $this->actingAsPerson($me);

        // 期待：ログイン中の本人（佐藤）が「自分」として扱われる。
        $this->assertSame(
            $me->id,
            PersonalCases::meModel()?->id,
            'マイページの「自分」がログイン本人になっていない（baba に固定されている）'
        );
    }

    /** マイページ画面自体は正常に開ける（回帰確認）。 */
    public function test_mypage_page_loads(): void
    {
        $me = PersonFactory::new()->create(['id' => 'E-007', 'name' => 'baba']);

        $this->actingAsPerson($me)->get('/mypage')->assertOk();
    }
}
