<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 「DBが空のときは見本（モック）データを見せる」作りを廃止したことの確認（2026-08-24 baba指摘）。
 *
 * 何が起きていたか：
 *   本番サーバーへ切り替えた直後はDBが空なので、マイページ・収支入力・社員の出勤可能日に
 *   架空の案件・架空の社員・架空の〇×△が「本物のように」表示されていた。
 *   とくに MY_ASSIGN_MOCK と seedMark は「その人にデータが無いとき」に出るため、
 *   本物のデータを入れたあとも消えない＝架空の予定を見て人を動かす事故になりうる。
 *
 * これから：DBがすべて。0件なら0件のまま表示する。
 */
class NoMockFallbackTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Person
    {
        return PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京']);
    }

    /** マイページ：DBが空でも、架空のアサイン（MY_ASSIGN_MOCK）を出さない。 */
    public function test_mypage_has_no_mock_assignments(): void
    {
        $res = $this->actingAsPerson($this->employee())->get('/mypage');

        $res->assertOk();
        $res->assertDontSee('MY_ASSIGN_MOCK', false);
        // 見本の案件ID（cases.js 由来）が画面に埋め込まれていないこと。
        $res->assertDontSee("'past_fes'", false);
    }

    /** 収支入力：DBが空でも、架空のアサインで収支を入力できてしまわない。 */
    public function test_mypage_finance_has_no_mock_assignments(): void
    {
        $res = $this->actingAsPerson($this->employee())->get('/mypage-finance');

        $res->assertOk();
        $res->assertDontSee('MY_ASSIGN_MOCK', false);
        $res->assertDontSee("'past_fes'", false);
    }

    /** 社員の出勤可能日：架空の社員名と、架空の〇×△（seedMark）を出さない。 */
    public function test_employee_availability_has_no_mock_employees(): void
    {
        $res = $this->actingAsPerson($this->employee())->get('/employee-availability');

        $res->assertOk();
        $res->assertDontSee('seedMark', false);   // 名前と日から〇×△をでっち上げる関数
        $res->assertDontSee('seedMemo', false);   // 備考をでっち上げる関数
        $res->assertDontSee('佐藤 健太');          // 見本の社員名
        // 凍結モックの案件ファイルを読み込んでいないこと＝架空の「大型」の印が付かない。
        $res->assertDontSee('<script src="/ecs/data/cases.js"', false);
    }

    /** 出勤可能日の「自分」＝ログイン中の本人（一覧の先頭ではない）。 */
    public function test_employee_availability_me_is_logged_in_person(): void
    {
        PersonFactory::new()->create(['id' => 'E-001', 'name' => '先頭の人', 'permission' => 'employee', 'office' => '東京']);
        $me = PersonFactory::new()->create(['id' => 'E-900', 'name' => '自分', 'permission' => 'manager', 'office' => '東京']);

        $res = $this->actingAsPerson($me)->get('/employee-availability');

        $res->assertOk();
        $res->assertSee('"id":"E-900"', false);   // ECS_ME が自分になっている
    }

    /** 出勤可能日の保存先は、送られてきた employee_id ではなくログイン中の本人。 */
    public function test_employee_availability_saves_to_logged_in_person(): void
    {
        $other = PersonFactory::new()->create(['id' => 'E-001', 'name' => '他人', 'permission' => 'employee', 'office' => '東京']);
        $me = PersonFactory::new()->create(['id' => 'E-900', 'name' => '自分', 'permission' => 'manager', 'office' => '東京']);

        $this->actingAsPerson($me)->postJson('/employee-availability/save', [
            'employee_id' => $other->id,          // 他人のIDを送りつけても…
            'period' => '2026-09',
            'state' => ['2026-9-5' => 'ok'],
            'memo' => '',
        ])->assertOk();

        // …保存されるのは自分の行だけ。
        // ※ date は "YYYY-MM-DD 00:00:00" で入る（既知）。検索するときは時刻ぶんに注意。
        $this->assertDatabaseHas('shift_preferences', ['staff_id' => 'E-900', 'date' => '2026-09-05 00:00:00']);
        $this->assertDatabaseMissing('shift_preferences', ['staff_id' => 'E-001']);
    }
}
