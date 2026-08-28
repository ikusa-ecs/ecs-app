<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ本人が、確定したあとも「自分が応募のときに書いた一言」を見返せる（2026-08-28 baba要望）。
 *
 * ⚠ 名前がまぎらわしいので必ず分けること。
 *   applications.note   … 本人 → 運営（エントリーのときに書いた一言）＝ここで扱うもの
 *   assignments.remark  … 運営 → 本人（担当からの連絡）
 *   画面ではどちらも「myNote」という名前を使っていたので、こちらは entryNote で渡す。
 */
class StaffPortalEntryNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_tab_shows_my_entry_comment(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'S-901', 'name' => 'テスト スタッフ', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(9);

        $p = Project::create([
            'id' => 'P-EN', 'project_name' => '水合戦', 'content_names' => ['水合戦'],
            'client' => 'テスト株式会社', 'start_date' => $day->toDateString(),
            'office' => '東京', 'status' => '確定', 'staff_published' => true,
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $day->toDateString(), 'role' => 'FC', 'status' => '確定',
            'remark' => '当日は受付をお願いします',
        ]);
        Application::create([
            'project_id' => $p->id, 'staff_id' => $me->id, 'intent' => '希望', 'note' => '午後から入れます',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')
            ->assertOk()
            ->assertViewHas('published', function ($rows) {
                $r = collect($rows)->firstWhere('id', 'P-EN');

                return $r
                    // 自分が書いた一言（本人 → 運営）
                    && $r['entryNote'] === '午後から入れます'
                    // ⚠ 担当からの連絡（運営 → 本人）と混ざっていないこと
                    && $r['myNote'] === '当日は受付をお願いします';
            })
            ->assertSee('応募のときに書いた一言', false);
    }

    /** 一言を書いていない案件は空のまま（空欄の見出しを出さない）。 */
    public function test_no_comment_stays_empty(): void
    {
        $me = PersonFactory::new()->create([
            'id' => 'S-902', 'name' => 'コメント無し', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
        $day = Carbon::today()->copy()->addDays(9);

        $p = Project::create([
            'id' => 'P-EN2', 'project_name' => '謎解き', 'content_names' => ['謎解き'],
            'start_date' => $day->toDateString(), 'office' => '東京',
            'status' => '確定', 'staff_published' => true,
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $day->toDateString(), 'role' => 'FC', 'status' => '確定',
        ]);

        $this->actingAsPerson($me)->get('/staff-portal')
            ->assertOk()
            ->assertViewHas('published', fn ($rows) => collect($rows)->firstWhere('id', 'P-EN2')['entryNote'] === '');
    }
}
