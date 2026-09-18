<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Support\OfficeSettings;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ公開ボード（App\Http\Controllers\AssignPublishController）の結合テスト。
 * テスト仕様書 IT-PUB-01 / IT-PUB-02。
 *
 * ・RefreshDatabase：テストごとにメモリ上のDBをまっさらにする（本番/開発データは触らない）。
 * ・actingAsPerson()：ログイン＋メール2段階認証(OTP)確認済みの状態を作る（使わないと /otp に飛ばされる）。
 * ・ルートはすべて tier:employee 配下（社員以上）。テストでは既定の社員でログインする。
 *
 * 実装で確認した入力名・保存先：
 *   POST /assign-publish/set      … ids[]（案件ID配列）＋ publish（真偽）→ projects.staff_published
 *   POST /assign-publish/time     … id ＋ staff_meet / staff_leave → projects.staff_meet_time / staff_leave_time
 *   POST /assign-publish/notice   … notice → settings(key='staff_notice')
 *   POST /assign-publish/deadline … date（Y-m-d）→ settings(key='entry_deadline')
 * いずれも JSON で {ok:true, ...} を返す。
 */
class AssignPublishBoardTest extends TestCase
{
    use RefreshDatabase;

    /** IT-PUB-01：公開ON→OFFで staff_published が true/false に保存される。 */
    public function test_set_publish_toggles_staff_published_in_db(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create([
            'start_date'      => '2026-09-01',
            'staff_published' => false,
        ]);

        // 公開ON。
        $on = $this->actingAsPerson($employee)->postJson('/assign-publish/set', [
            'ids'     => [$project->id],
            'publish' => true,
        ]);
        $on->assertOk()->assertJson(['ok' => true, 'updated' => 1]);
        $this->assertTrue((bool) Project::find($project->id)->staff_published);

        // 公開OFF。
        $off = $this->actingAsPerson($employee)->postJson('/assign-publish/set', [
            'ids'     => [$project->id],
            'publish' => false,
        ]);
        $off->assertOk()->assertJson(['ok' => true, 'updated' => 1]);
        $this->assertFalse((bool) Project::find($project->id)->staff_published);
    }

    /**
     * IT-PUB-01（補足）：複数案件をまとめて公開ONにでき、対象だけ true になる（他は据え置き）。
     */
    public function test_set_publish_updates_multiple_projects_only(): void
    {
        $employee = PersonFactory::new()->create();
        $a = ProjectFactory::new()->create(['start_date' => '2026-09-01', 'staff_published' => false]);
        $b = ProjectFactory::new()->create(['start_date' => '2026-09-02', 'staff_published' => false]);
        $c = ProjectFactory::new()->create(['start_date' => '2026-09-03', 'staff_published' => false]);

        $res = $this->actingAsPerson($employee)->postJson('/assign-publish/set', [
            'ids'     => [$a->id, $b->id],
            'publish' => true,
        ]);
        $res->assertOk()->assertJson(['ok' => true, 'updated' => 2]);

        $this->assertTrue((bool) Project::find($a->id)->staff_published);
        $this->assertTrue((bool) Project::find($b->id)->staff_published);
        // 対象外は据え置き（触っていない）。
        $this->assertFalse((bool) Project::find($c->id)->staff_published);
    }

    /**
     * IT-PUB-02：集合/解散時間・お知らせ文・一斉締切日を保存し、再取得しても残っている。
     */
    public function test_time_notice_and_deadline_are_saved_and_persist(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);

        // ── 集合/解散時間（スタッフ向け）──
        $this->actingAsPerson($employee)->postJson('/assign-publish/time', [
            'id'          => $project->id,
            'staff_meet'  => '08:30',
            'staff_leave' => '18:00',
        ])->assertOk()->assertJson(['ok' => true]);

        // ── お知らせ文 ──
        $this->actingAsPerson($employee)->postJson('/assign-publish/notice', [
            'notice' => 'テスト用のお知らせ文です。',
        ])->assertOk()->assertJson(['ok' => true]);

        // ── 一斉締切日 ──
        $this->actingAsPerson($employee)->postJson('/assign-publish/deadline', [
            'date' => '2026-08-25',
        ])->assertOk()->assertJson(['ok' => true]);

        // DBに直接残っていること（新しく取り直したモデル/設定で確認）。
        $fresh = Project::find($project->id);
        $this->assertSame('08:30', $fresh->staff_meet_time);
        $this->assertSame('18:00', $fresh->staff_leave_time);
        // お知らせ文・締切日は**拠点ごと**に持つ（2026-08-25 baba要望）。
        // この社員の拠点＝東京なので、東京のキーに入っていること。
        $this->assertSame('テスト用のお知らせ文です。',
            OfficeSettings::get(OfficeSettings::NOTICE, '東京'));
        $this->assertSame('2026-08-25',
            OfficeSettings::get(OfficeSettings::DEADLINE, '東京'));
        // 全国共通だった置き場には書かない（他拠点まで変わってしまうため）。
        $this->assertNull(Setting::get('staff_notice'));
        $this->assertNull(Setting::get('entry_deadline'));

        // 画面(index)を開き直しても、お知らせ・締切がそのまま渡っている＝再取得で残る。
        $view = $this->actingAsPerson($employee)->get('/assign-publish');
        $view->assertOk();
        $view->assertViewHas('notice', 'テスト用のお知らせ文です。');
        $view->assertViewHas('entryDeadline', '2026-08-25');
    }

    /**
     * IT-PUB-02（補足）：空文字を送ると集合/解散時間は「未設定(null)」に戻る
     * （＝社員の時間をそのまま使う扱いに戻す実装）。
     */
    public function test_empty_time_resets_to_null(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create([
            'start_date'      => '2026-09-01',
            'staff_meet_time' => '09:00',
            'staff_leave_time'=> '17:00',
        ]);

        $this->actingAsPerson($employee)->postJson('/assign-publish/time', [
            'id'          => $project->id,
            'staff_meet'  => '',
            'staff_leave' => '',
        ])->assertOk()->assertJson(['ok' => true]);

        $fresh = Project::find($project->id);
        $this->assertNull($fresh->staff_meet_time);
        $this->assertNull($fresh->staff_leave_time);
    }

    /**
     * お知らせ文・締切日は**拠点ごと**（2026-08-25 baba要望）。
     * ⚠ 以前は全国共通だったため、東京で直すと東北のスタッフ画面まで変わっていた。
     */
    public function test_notice_and_deadline_are_kept_per_office(): void
    {
        $tokyo = PersonFactory::new()->create(['id' => 'E-101', 'office' => '東京', 'permission' => 'employee']);
        $tohoku = PersonFactory::new()->create(['id' => 'E-102', 'office' => '東北', 'permission' => 'employee']);

        $this->actingAsPerson($tokyo)->postJson('/assign-publish/notice', [
            'notice' => '東京のお知らせです。', 'office' => '東京',
        ])->assertOk();
        $this->actingAsPerson($tohoku)->postJson('/assign-publish/notice', [
            'notice' => '東北のお知らせです。', 'office' => '東北',
        ])->assertOk();

        $this->assertSame('東京のお知らせです。', OfficeSettings::get(OfficeSettings::NOTICE, '東京'));
        $this->assertSame('東北のお知らせです。', OfficeSettings::get(OfficeSettings::NOTICE, '東北'));

        // それぞれの画面には自分の拠点の文が出る。
        $this->actingAsPerson($tokyo)->get('/assign-publish')
            ->assertViewHas('notice', '東京のお知らせです。');
        $this->actingAsPerson($tohoku)->get('/assign-publish')
            ->assertViewHas('notice', '東北のお知らせです。');
    }

    /**
     * 一般社員は、他の拠点のお知らせ文を書き換えられない。
     * ⚠ 画面から送られてきた拠点名をそのまま信じると、他拠点のスタッフ画面を変えられてしまう。
     */
    public function test_employee_cannot_edit_another_office_notice(): void
    {
        $tokyo = PersonFactory::new()->create(['id' => 'E-103', 'office' => '東京', 'permission' => 'employee']);

        $this->actingAsPerson($tokyo)->postJson('/assign-publish/notice', [
            'notice' => '勝手に書き換え', 'office' => '東北',
        ])->assertStatus(403);

        $this->assertSame('', OfficeSettings::get(OfficeSettings::NOTICE, '東北'));
    }

    /**
     * 「募集しない」案件が公開ボードで分かる（2026-09-18 baba指摘
     * 「メンバー募集しないにしてるのにスタッフ公開ボードに出てるのはなぜ？」）。
     *
     * ⚠ 一覧からは**消さない**。公開はスタッフ画面の「確定アサイン」も兼ねているので、
     *   消すと入っている人が自分の担当を見られなくなる。
     *   代わりに「募集なし」の札を出し、絞り込み「募集なしを隠す」で並べないようにできる。
     */
    public function test_募集しない案件は募集なしと分かる(): void
    {
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $no = ProjectFactory::new()->create([
            'office' => '東京', 'is_recruiting' => false,
            'start_date' => now()->addDays(5)->format('Y-m-d'),
        ]);
        $yes = ProjectFactory::new()->create([
            'office' => '東京', 'is_recruiting' => true,
            'start_date' => now()->addDays(6)->format('Y-m-d'),
        ]);

        $cases = collect(
            $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->original->getData()['cases']
        );

        // 両方とも一覧には出る（消さない）。
        $this->assertNotNull($cases->firstWhere('id', $no->id), '募集しない案件が一覧から消えています');
        $this->assertFalse($cases->firstWhere('id', $no->id)['recruit'], '募集の有無が渡っていません');
        $this->assertTrue($cases->firstWhere('id', $yes->id)['recruit']);

        $html = $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->getContent();
        // 札と絞り込みがあること。
        $this->assertStringContainsString('募集なし', $html, '「募集なし」の札がありません');
        $this->assertStringContainsString('募集なしを隠す', $html, '絞り込みがありません');
        // ⚠ この画面もカードを作り直すつくり＝詰め替えを忘れると札も絞り込みも効かない。
        $this->assertStringContainsString('recruit: c.recruit', $html, '詰め替え（これが無いと効かない）');
    }

    /**
     * 他拠点の案件が混ざっていることが分かる（2026-09-18 baba指摘
     * 「東京のスタッフ公開ボードに名古屋の案件が混じってた」）。
     *
     * ⚠ 混ざること自体は決まりどおり＝その案件に自分の拠点がヘルプ／巻き取りで関わっているため。
     *   ⚠ 他拠点の社員を1人アサインしただけでも、自動でヘルプが記録される（CrossOfficeHelp）。
     *   画面に登録拠点と関わりを出して、見て分かるようにする。
     */
    public function test_他拠点の案件だと分かる(): void
    {
        $me = PersonFactory::new()->create(['office' => '東京', 'must_onboard' => false]);

        $mine = ProjectFactory::new()->create([
            'office' => '東京', 'start_date' => now()->addDays(5)->format('Y-m-d'),
        ]);
        $other = ProjectFactory::new()->create([
            'office' => '名古屋', 'start_date' => now()->addDays(6)->format('Y-m-d'),
        ]);
        \App\Models\ProjectShare::create([
            'project_id' => $other->id, 'office' => '東京', 'kind' => 'ヘルプ',
        ]);

        $cases = collect(
            $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->original->getData()['cases']
        );

        $row = $cases->firstWhere('id', $other->id);
        $this->assertNotNull($row, '関わっている他拠点の案件が出ていません');
        $this->assertTrue($row['otherOffice'], '他拠点の案件だと分かる印がありません');
        $this->assertSame('名古屋', $row['office']);
        $this->assertSame(
            [['label' => '名古屋からヘルプ', 'kind' => 'ヘルプ']],
            $row['shareTags'],
            'どう関わっているかが出ていません'
        );

        // 自拠点の案件には印を付けない。
        $this->assertFalse($cases->firstWhere('id', $mine->id)['otherOffice']);

        $html = $this->actingAsPerson($me)->get('/assign-publish')->assertOk()->getContent();
        $this->assertStringContainsString('otherOffice: !!c.otherOffice', $html, '詰め替え（これが無いと出ない）');
        $this->assertStringContainsString('function otherOfficeHtml(', $html, '札を描くところ');
        $this->assertStringContainsString('自拠点の案件だけ', $html, '絞り込みがありません');
    }
}
