<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 日別ボード：公開したあとで足した人を、まとめて確定にできること（2026-08-28 baba指摘）。
 *
 * 【なぜ要るか】
 * 公開ずみの案件カードは「✓ 確定にする」「📣 スタッフに公開」が消える。
 * もう一度やる必要が無いので**これは仕様**。
 * ⚠ ただし**公開したあとで足した人は「仮」から始まる**ので、
 *   そのままだと本人の画面にこの案件が出ない。
 *   前は名前の横の「仮」を1人ずつ押すしかなかったので、まとめて確定にするボタンを足した。
 */
class BoardConfirmAfterPublishTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'role' => 'employee',
            'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 公開ずみの案件でも、仮のメンバーをまとめて確定にできる。 */
    public function test_members_added_after_publishing_can_be_confirmed(): void
    {
        $admin = $this->admin();
        $day = Carbon::today()->copy()->addDays(5);

        $p = Project::create([
            'id' => 'P-PUB', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true, 'is_recruiting' => true,
        ]);

        $already = PersonFactory::new()->create(['id' => 'S-201', 'name' => '先の人', 'office' => '東京']);
        $later = PersonFactory::new()->create(['id' => 'S-202', 'name' => '後の人', 'office' => '東京']);

        // 公開のときに確定になった人。
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $already->id,
            'date' => $day->toDateString(), 'role' => 'MC', 'status' => '確定',
        ]);
        // ⚠ 公開したあとで足した人＝「仮」から始まる。
        $laterRow = Assignment::create([
            'project_id' => $p->id, 'staff_id' => $later->id,
            'date' => $day->toDateString(), 'role' => 'FC', 'status' => '仮',
        ]);

        $this->actingAsPerson($admin)->postJson('/projects/confirm-members', ['project_id' => $p->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'confirmed' => 1]);

        $this->assertSame('確定', $laterRow->fresh()->status, '後から足した人が確定にならない＝本人の画面に出ない');
    }

    /**
     * 画面にボタンを出す仕掛けが残っていること。
     * ⚠ Blade の @verbatim の切れ目を間違えるとスクリプトが途中から消える。
     *   画面は真っ白にならないので気づけない＝テストで見張る。
     */
    public function test_board_screen_has_the_button(): void
    {
        $admin = $this->admin();

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertSee('function fixMembers', false)
            ->assertSee('仮の${kari}名を確定にする', false)
            // 募集中でも「✓ 確定にする」を出す仕掛け（stat と pubOn を分けて見ている）。
            ->assertSee("if (stat === 'todo' || stat === 'adj')", false)
            ->assertSee('募集中', false)
            // いちばん最後の方の関数も残っているか。
            ->assertSee('function markPub', false);
    }

    /**
     * ⚠ ここが本題（2026-08-28 baba指摘）。
     * スタッフ公開ボードの「公開」は**エントリーを募る操作**で、その時点では誰が入れるかも分からない。
     * なのに以前は「公開ずみ」を最優先の状態にしていたため、
     * **募集をかけた瞬間に「✓ 確定にする」（＝メンバーを確定にする）ボタンが消えて**いた。
     * 公開（募集中か）と案件の進み具合を別々に渡していることを見張る。
     */
    public function test_published_project_still_reports_its_own_status(): void
    {
        $admin = $this->admin();
        $day = Carbon::today()->copy()->addDays(7);

        // 募集をかけたが、案件はまだ調整中＝人はこれから決める、という普通の状態。
        Project::create([
            'id' => 'P-REC', 'project_name' => '謎解き', 'content_names' => ['謎解き'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '調整中', 'staff_published' => true, 'is_recruiting' => true,
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertViewHas('boardCases', function ($cases) {
                $c = collect($cases)->firstWhere('id', 'P-REC');

                return $c
                    && $c['pubOn'] === true      // 募集中
                    && $c['stat'] === 'adj';     // でも案件はまだ調整中＝確定ボタンが要る
            });
    }
}
