<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\StaffInvoiceCheck;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * スタッフ別アサイン（請求書チェック）（2026-10-13 baba）。
 * 確かめたいこと：
 *   ・その月にアサインのある「スタッフ」だけが並び、明細（日付・案件・役割）が出る。社員・キャンセルは出さない。
 *   ・📨／✅ は押した瞬間に保存され、誰が付けたかが残る。外せば消える。
 *   ・中止の案件も消さずに印を付けて出す（キャンセル料の請求があり得る）。
 */
class StaffInvoiceCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_assignments_are_listed_with_details(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '担当花子']);
        $s1 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '請求太郎']);
        $s2 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '出ない次郎']);
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '社員三郎']);

        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-12', 'project_name' => '戦国運動会', 'client' => 'A社',
            'format' => 'リアルロング', 'start_time' => '7:00', 'end_time' => '18:30', 'location' => "代々木公園\n東京都渋谷区"]);
        $x = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-20', 'project_name' => '水合戦', 'client' => 'B社', 'is_cancelled' => true]);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-12']);
        Assignment::create(['project_id' => $x->id, 'staff_id' => $s1->id, 'role' => 'MC', 'status' => '確定', 'date' => '2026-10-20']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s2->id, 'role' => 'FC', 'status' => 'キャンセル', 'date' => '2026-10-12']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $emp->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-12']);

        $res = $this->actingAsPerson($me)->get('/staff-invoice-check?ym=2026-10&tab=check');
        $res->assertOk()
            ->assertSee('請求太郎')
            // 名前で探す（2026-10-15）＝行に名前の印が付いていること
            ->assertSee('id="siSearch"', false)
            ->assertSee('data-name="請求太郎"', false)
            ->assertSee('data-kana="', false)   // よみがなでも探せる（2026-10-15）
            ->assertSee('明細 1件・中止 1')   // 中止の案件は一覧に出すが件数に数えない（2026-10-21 baba）
            ->assertSee('戦国運動会')
            ->assertSee('案件中止')
            // 実施形態・集合／解散・会場住所（2026-10-13）。コピーの文にも2行目で入る。
            ->assertSee('リアルロング')
            ->assertSee('集合 7:00・解散 18:30')
            ->assertSee('代々木公園 東京都渋谷区')
            ->assertSee("　FC（巡回ファシリ）\n　リアルロング／集合 7:00・解散 18:30／代々木公園 東京都渋谷区", false)
            ->assertDontSee('data-id="'.$s2->id.'"', false)   // キャンセルだけの人は一覧の行に出ない（手当の入力欄の選択肢には出る）
            ->assertDontSee('社員三郎');    // 社員は請求書を出さない

        // 📨 を付ける → 誰が付けたかが残る。外せば消える。
        $this->postJson('/staff-invoice-check/mark', ['staff_id' => $s1->id, 'ym' => '2026-10', 'field' => 'received', 'on' => true])
            ->assertOk()->assertJson(['ok' => true, 'by' => '担当花子']);
        $row = StaffInvoiceCheck::where('staff_id', $s1->id)->where('ym', '2026-10')->first();
        $this->assertNotNull($row->received_at);
        $this->assertNull($row->checked_at);

        $this->postJson('/staff-invoice-check/mark', ['staff_id' => $s1->id, 'ym' => '2026-10', 'field' => 'checked', 'on' => true])->assertOk();
        $this->postJson('/staff-invoice-check/mark', ['staff_id' => $s1->id, 'ym' => '2026-10', 'field' => 'received', 'on' => false])->assertOk();
        $row->refresh();
        $this->assertNull($row->received_at);
        $this->assertNotNull($row->checked_at);
        $this->assertSame('担当花子', $row->checked_by);

        // 別の月の印とは混ざらない。
        $this->get('/staff-invoice-check?ym=2026-11&tab=check')->assertOk()->assertSee('この月にアサインのあるスタッフはいません');
    }

    /** 請求書PDFの置き場所のURLを入れて、画面から開ける（2026-10-13）。おかしな文字は受け付けない。 */
    public function test_folder_url_can_be_saved(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $this->actingAsPerson($me)->get('/staff-invoice-check?tab=check')->assertOk()->assertSee('まだ入っていません');

        $url = 'https://drive.google.com/drive/folders/abc123';
        $this->post('/staff-invoice-check/folder', ['url' => $url])->assertRedirect();
        $this->get('/staff-invoice-check?tab=check')->assertOk()->assertSee($url);

        $this->post('/staff-invoice-check/folder', ['url' => 'javascript:alert(1)'])->assertSessionHasErrors('url');
        $this->get('/staff-invoice-check?tab=check')->assertSee($url);   // 前の値のまま

        $this->post('/staff-invoice-check/folder', ['url' => ''])->assertRedirect();
        $this->get('/staff-invoice-check?tab=check')->assertSee('まだ入っていません');
    }

    public function test_staff_cannot_open(): void
    {
        $staff = PersonFactory::new()->create(['role' => 'staff', 'permission' => 'staff']);
        $res = $this->actingAsPerson($staff)->get('/staff-invoice-check');
        $this->assertNotSame(200, $res->status());
    }
}
