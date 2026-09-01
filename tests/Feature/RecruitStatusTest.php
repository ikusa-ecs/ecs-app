<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Support\RecruitStatus;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「スタッフの画面にどう見えているか」を社員側の画面と合わせる（2026-08-28 baba指摘）。
 *
 * 【何が問題だったか】
 * 日別ボードは「公開しているか」だけを見て「募集中」と出していた。
 * ⚠ ところが**スタッフの画面では、人数が埋まった案件は「締切・満員」**になっていて
 *   エントリーできない。同じ案件が、社員から見ると募集中・スタッフから見ると締切でズレていた。
 *
 * ⚠ この状態はどこにも保存していない＝**運営人数を増やせば、その場でまた募集中に戻る**
 *   （追加募集のために公開し直す必要はない）。
 */
class RecruitStatusTest extends TestCase
{
    use RefreshDatabase;

    /** 人数が埋まっていれば満員。 */
    public function test_full_when_filled_reaches_the_need(): void
    {
        $this->assertTrue(RecruitStatus::isFull(5, 5));
        $this->assertTrue(RecruitStatus::isFull(5, 6));
        $this->assertFalse(RecruitStatus::isFull(5, 4));
        $this->assertSame(1, RecruitStatus::remaining(5, 4));
        $this->assertSame(0, RecruitStatus::remaining(5, 9));
    }

    /**
     * ⚠ 運営人数が未入力（空・0）のときは既定の人数を使う。
     * 0 のまま数えると、公開した瞬間に「満員」になってエントリーできなくなる。
     */
    public function test_missing_need_uses_the_default(): void
    {
        $this->assertSame(RecruitStatus::DEFAULT_NEED, RecruitStatus::need(0));
        $this->assertSame(RecruitStatus::DEFAULT_NEED, RecruitStatus::need(null));
        $this->assertFalse(RecruitStatus::isFull(null, 1), '人数未入力なのに満員になっている');
    }

    /** ⚠ 運営人数を増やすと、その場でまた募集中に戻る（公開し直さなくてよい）。 */
    public function test_raising_the_need_reopens_it(): void
    {
        $this->assertTrue(RecruitStatus::isFull(5, 5));
        $this->assertFalse(RecruitStatus::isFull(8, 5), '人数を増やしたのに締切のままになっている');
    }

    /** 日別ボードが、スタッフ画面と同じ必要人数を受け取っている。 */
    public function test_board_receives_the_staff_facing_need(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(6);

        Project::create([
            'id' => 'P-NEED', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true,
            // 運営人数は未入力のまま公開された、という状況。
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertViewHas('boardCases', function ($cases) {
                $c = collect($cases)->firstWhere('id', 'P-NEED');

                return $c && $c['needStaff'] === RecruitStatus::DEFAULT_NEED;
            })
            // 画面側の判定と「追加募集する」の仕掛けが残っているか（@verbatim 対策）。
            ->assertSee('function isFullForStaff', false)
            ->assertSee('function addRecruit', false)
            ->assertSee('function recruitBadge', false);
    }

    /** スタッフ画面の「満員」の判定と、社員側の判定がそろっている。 */
    public function test_staff_screen_uses_the_same_number(): void
    {
        $staff = PersonFactory::new()->create([
            'id' => 'S-950', 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(6);

        $p = Project::create([
            'id' => 'P-FULL', 'project_name' => '謎解き', 'content_names' => ['謎解き'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true, 'is_recruiting' => true,
            'required_count' => 2,
        ]);
        foreach (['S-951', 'S-952'] as $sid) {
            PersonFactory::new()->create(['id' => $sid, 'role' => 'staff', 'office' => '東京']);
            Assignment::create([
                'project_id' => $p->id, 'staff_id' => $sid,
                'date' => $day->toDateString(), 'role' => 'FC', 'status' => '確定',
            ]);
        }

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $job = collect($jobs)->firstWhere('id', 'P-FULL');

        $this->assertSame(2, $job['need']);
        $this->assertSame(2, $job['filled']);
        $this->assertTrue(RecruitStatus::isFull($p->required_count, $job['filled']),
            'スタッフ画面では満員なのに、社員側の判定では満員になっていない');
    }

    // ─────────────────────────────────────────────────────────────
    // 「🔒 この人数で足りている」（2026-09-01 スタッフからのご意見）
    //
    // ⚠ 運営人数はセールスが書いた数字なので**変えない**。人数が埋まっていなくても
    //   アサイン担当が「これで足りている」と決められるようにし、そのとき
    //   「募集中 あと◯名」を消す。
    // ⚠ 確定にするだけでは締めない＝**本当に人が足りなくて募集を続けたい案件がある**（baba）。
    // ─────────────────────────────────────────────────────────────

    /** 人数が足りていなくても募集だけ締められる。⚠ 運営人数は変えない。 */
    public function test_closing_the_recruitment_keeps_the_planned_headcount(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-010', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $p = Project::create([
            'id' => 'P-CLOSE', 'project_name' => '運動会', 'content_names' => ['運動会'],
            'start_date' => Carbon::today()->copy()->addDays(6)->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true, 'is_recruiting' => true,
            'required_count' => 8,
        ]);

        $this->actingAsPerson($admin)
            ->postJson('/projects/cells', ['id' => 'P-CLOSE', 'recruit' => false])
            ->assertOk();

        $p->refresh();
        $this->assertFalse((bool) $p->is_recruiting, '募集が締まっていない');
        $this->assertSame(8, (int) $p->required_count,
            '⚠ 運営人数を書き換えています。セールスが入れた予定はそのまま残すこと。');
        $this->assertSame('確定', $p->status, 'アサイン状況まで変えている');
    }

    /** 締めたら「募集中 あと◯名」を出さない（社員の画面とスタッフの画面をそろえる）。 */
    public function test_the_label_is_empty_once_the_recruitment_is_closed(): void
    {
        $p = new Project(['required_count' => 8]);
        $p->staff_published = true;

        $p->is_recruiting = true;
        $this->assertSame('募集中（あと2名）', RecruitStatus::label($p, 6));

        $p->is_recruiting = false;
        $this->assertSame('', RecruitStatus::label($p, 6),
            '募集を締めたのに「募集中 あと◯名」が残っている');
    }

    /**
     * ⚠ 「確定にする」だけでは募集を締めない。
     *   本当に人が足りていなくて、確定にしても募集を続けたい案件があるため（2026-09-01 baba）。
     */
    public function test_confirming_alone_does_not_close_the_recruitment(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-011', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $p = Project::create([
            'id' => 'P-FIX', 'project_name' => '謎解き', 'content_names' => ['謎解き'],
            'start_date' => Carbon::today()->copy()->addDays(6)->toDateString(), 'office' => '東京',
            'status' => '調整中', 'staff_published' => true, 'is_recruiting' => true,
            'required_count' => 8,
        ]);

        $this->actingAsPerson($admin)
            ->postJson('/projects/cells', ['id' => 'P-FIX', 'status' => '確定'])
            ->assertOk();

        $p->refresh();
        $this->assertSame('確定', $p->status);
        $this->assertTrue((bool) $p->is_recruiting,
            '⚠ 確定にしただけで募集を締めています。人が足りず募集を続けたい案件があります。');
    }

    /** 締めたあとも「＋ 追加募集する」で戻せる（確定後の追加募集はよくある・baba）。 */
    public function test_the_recruitment_can_be_reopened(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-012', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        $p = Project::create([
            'id' => 'P-REOPEN', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'start_date' => Carbon::today()->copy()->addDays(6)->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true, 'is_recruiting' => false,
            'required_count' => 8,
        ]);

        $this->actingAsPerson($admin)
            ->postJson('/projects/cells', ['id' => 'P-REOPEN', 'recruit' => true])
            ->assertOk();

        $this->assertTrue((bool) $p->refresh()->is_recruiting, '募集を再開できていない');
    }

    /** 画面側の仕掛けが残っているか（@verbatim の中なので消えても気づけない）。 */
    public function test_the_board_has_the_close_button(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-013', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertSee('function closeRecruit', false)
            ->assertSee('この人数で足りている', false)
            // 募集を続けているかがボードに渡っていないと、締めてもバッジが消えない。
            ->assertSee('function bRecruit', false)
            // 案件の詳細へ行くボタン（2026-09-01 baba要望）。
            // ⚠ 案件名のリンクだけだと押せることに気づけない、というご意見だった。
            ->assertSee('function detailBtnHtml', false)
            ->assertSee('案件の詳細 →', false)
            // 実施形態のバッジ（2026-09-01 baba要望）。
            ->assertSee('function fmtBadgeHtml', false)
            // ⚠ 締めたあとの見え方（2026-09-01 baba指摘）。
            //   締めたのに人数のバーが「足りていない」ままだと、まだ足すのか分からない。
            //   バーを満たす（settled）／数字に「この人数で確定」／その日の「あと◯名」から外す。
            ->assertSee('const settled', false)
            ->assertSee('この人数で確定・予定', false)
            ->assertSee('const needOf', false);
    }

    /**
     * ⚠ スタッフ画面のカレンダー：押したら「押した」と分かること（2026-09-01 baba報告）。
     *   エラーは出ていないのに「クリックしても何も反応しない」と言われた。原因は2つ：
     *   ・開いた中身がカレンダーの**下**に出るので、画面の外にあって気づけない（スクロールしていなかった）
     *   ・押した案件に**印が付かない**（カレンダーを描き直していなかった）
     */
    public function test_the_staff_calendar_shows_that_it_was_tapped(): void
    {
        $blade = (string) file_get_contents(resource_path('views/staff_portal.blade.php'));

        $this->assertStringContainsString('function openJobDetail', $blade);
        // 押したら開いたところまで動かす。
        $this->assertStringContainsString('wrap.scrollIntoView', $blade,
            '押しても開いた中身までスクロールしないので、画面の外にあると気づけません。');
        // 押した案件に印を付ける＝カレンダーを描き直す。
        $this->assertStringContainsString('just-opened', $blade);
        $this->assertStringContainsString('.jc-job.picked', $blade);

        // ⚠ 絞り込み中でも、押した案件は一覧から消さない（2026-09-01 baba指摘）。
        //   「募集中のみ」でエントリーすると『エントリー中』になって絞り込みから外れ、
        //   押した瞬間に消えていた＝できたのかどうか分からない。
        $this->assertStringContainsString('keepVisible.has(j.id)', $blade,
            '絞り込み中にエントリーすると、その案件が一覧から消えてしまいます。');
        $this->assertStringContainsString('keepVisible.add(j.id)', $blade);
    }

    /**
     * 日別ボードに実施形態が渡っている（2026-09-01 baba要望）。
     * ⚠ 色の振り分けはサーバーがやる（正本＝ProjectFormats::badgeCode）。
     *   画面側で判定を書き直すと、案件一覧と色が食い違う。
     */
    public function test_the_board_receives_the_event_format(): void
    {
        $admin = PersonFactory::new()->create([
            'id' => 'E-014', 'role' => 'employee', 'permission' => 'admin',
            'office' => '東京', 'must_onboard' => false,
        ]);
        Project::create([
            'id' => 'P-FMT', 'project_name' => '運動会', 'content_names' => ['運動会'],
            'start_date' => Carbon::today()->copy()->addDays(5)->toDateString(), 'office' => '東京',
            'status' => '調整中', 'format' => 'リアルロング',
        ]);

        $this->actingAsPerson($admin)->get('/assign')
            ->assertOk()
            ->assertViewHas('boardCases', function ($cases) {
                $c = collect($cases)->firstWhere('id', 'P-FMT');

                return $c
                    && ($c['format'] ?? null) === 'リアルロング'
                    // ⚠ リアルロングを「リアル」と同じ色にしない（手当が変わるところなので見分けが要る）。
                    && ($c['fmtCls'] ?? null) === 'fmt-long';
            });
    }
}
