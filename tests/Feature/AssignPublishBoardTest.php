<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
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
        $this->assertSame('テスト用のお知らせ文です。', Setting::get('staff_notice'));
        $this->assertSame('2026-08-25', Setting::get('entry_deadline'));

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
}
