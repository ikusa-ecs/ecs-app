<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ログインの有効期限が切れた状態で画面から保存すると、何が返るか（2026-08-28 baba報告）。
 *
 * 【何が起きていたか】
 * スタッフが「エントリーする」を押すと
 *   保存に失敗しました（SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON）
 * と出ていた。
 * ⚠ これは「保存の宛先ではなく、**ログインの画面（HTML）が返ってきた**」という意味。
 *   画面は JSON を待っているので、HTML を読もうとして壊れていた。
 *   2段階認証の印（twofa_ok）がセッションから消えていると、こうなる
 *   （ページを開いたまま時間が経った・戻るボタンで古いページを開いた など）。
 *
 * 【直し方】
 * 画面からの保存（JSONを待っているもの）には、HTMLではなく
 * 「ログインし直してください」という JSON を返す。
 */
class AjaxSessionExpiredTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-830', 'name' => '応募 三郎', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(): Project
    {
        return Project::create([
            'id' => 'P-SES', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => Carbon::today()->copy()->addDays(10)->toDateString(),
            'office' => '東京', 'status' => '調整中',
            'is_recruiting' => true, 'staff_published' => true, 'required_count' => 5,
        ]);
    }

    /** ⚠ 2段階認証の印が無い状態でエントリーすると、HTMLではなくJSONで理由が返る。 */
    public function test_expired_two_factor_returns_json_not_html(): void
    {
        $p = $this->project();
        $staff = $this->staff();

        // ログインはしているが、2段階認証の印がセッションに無い状態を作る。
        $res = $this->actingAs($staff)
            ->withSession([])   // twofa_ok を入れない
            ->postJson('/staff-portal/entry', [
                'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望',
            ]);

        $res->assertStatus(401)
            ->assertJson(['ok' => false, 'reauth' => true]);

        $this->assertStringContainsString('ログインの有効期限', (string) $res->json('message'));
        $this->assertStringNotContainsString('<!DOCTYPE', $res->getContent(),
            'HTMLが返っている＝画面側で SyntaxError になる');
    }

    /** 初回設定がまだの人も、HTMLではなくJSONで理由が返る。 */
    public function test_not_onboarded_returns_json_not_html(): void
    {
        $p = $this->project();
        $staff = PersonFactory::new()->create([
            'id' => 'S-831', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => true,
        ]);

        $res = $this->actingAs($staff)
            ->withSession(['twofa_ok' => true])
            ->postJson('/staff-portal/entry', [
                'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望',
            ]);

        $res->assertStatus(409)->assertJson(['ok' => false, 'reauth' => true]);
        $this->assertStringNotContainsString('<!DOCTYPE', $res->getContent());
    }

    /** ふつうの画面（ブラウザで開くページ）は今までどおり案内のページへ飛ばす。 */
    public function test_normal_page_still_redirects(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->withSession([])->get('/staff-portal')
            ->assertRedirect(route('otp.challenge'));
    }

    /** 画面側に、HTMLが返ったときの受け皿がある。 */
    public function test_screen_has_the_guard(): void
    {
        $staff = $this->staff();
        $this->project();

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('function readJson', false)
            ->assertSee('function saveErrorMessage', false)
            ->assertSee('ログインの有効期限が切れています', false);
    }
}
