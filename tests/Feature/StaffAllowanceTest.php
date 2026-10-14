<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\StaffAllowance;
use App\Support\StaffAllowances;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 手当の記録（運転・スライド・事前レクチャー・その他）。2026-10-13 baba。
 * 確かめたいこと：
 *   ・請求書チェック（フォーム）と収支入力（JSON）の両方から入れられ、消せる。誰が入れたかが残る。
 *   ・入れた手当が「請求書の目安」に足される。手当だけの人（アサインなし）も一覧に出る。
 *   ・自動で数える手当（前泊・ロングなど）は選べない／その他は金額が要る／スタッフ以外には付けられない。
 */
class StaffAllowanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_from_both_screens_and_it_shows_in_the_draft(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '担当花子']);
        $s1 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '運転太郎']);
        $s2 = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '講師次郎']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-12', 'project_name' => '戦国運動会', 'client' => '株式会社テスト商事']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s1->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-12']);

        // ① 請求書チェックの画面から（フォーム）。
        $this->actingAsPerson($me)->post('/staff-allowances', [
            'staff_id' => $s1->id, 'project_id' => $p->id, 'date' => '2026-10-12', 'kind' => '運転手当', 'count' => 1,
        ])->assertRedirect()->assertSessionHas('ok');

        // ② 収支入力から（JSON）。案件のスタッフが読める。
        $this->getJson('/staff-allowances/project?project='.$p->id)->assertOk()
            ->assertJsonPath('staff.0.name', '運転太郎')
            ->assertJsonPath('items.0.kind', '運転手当')
            ->assertJsonPath('items.0.by', '担当花子');
        $this->postJson('/staff-allowances', ['staff_id' => $s1->id, 'project_id' => $p->id, 'date' => '2026-10-12',
            'kind' => 'スライド手当', 'count' => 2])->assertOk()->assertJsonPath('item.amount', 2000);

        // アサインの無い人（レクチャーだけ）・その他（金額を手で）。
        $this->postJson('/staff-allowances', ['staff_id' => $s2->id, 'date' => '2026-10-10', 'kind' => '事前レクチャー手当',
            'note' => '本能寺のレクチャー'])->assertOk();
        $this->postJson('/staff-allowances', ['staff_id' => $s2->id, 'date' => '2026-10-11', 'kind' => 'その他', 'amount' => 1500,
            'note' => '資料印刷'])->assertOk();

        $res = $this->get('/staff-invoice-check?ym=2026-10&tab=check')->assertOk()
            ->assertSee('請求書チェック・手当管理表')
            ->assertSee('運転手当：10/12')
            ->assertSee('スライド手当：10/12(2回)')
            ->assertSee('講師次郎')                                  // 手当だけの人も出る
            ->assertSee('事前レクチャー手当：10/10（本能寺のレクチャー）')
            ->assertSee('資料印刷：10/11')
            ->assertDontSee('✍ 手当の記録（10月）');   // 手当の記録はタブを切り替えたときだけ（2026-10-13）

        // 「✍ 手当の記録」のタブ＝入力欄と一覧。**はじめに開くのはこちら**（2026-10-13 baba）。月の移動でもタブのまま。
        $this->get('/staff-invoice-check?ym=2026-10&tab=allowances')->assertOk()
            ->assertSee('✍ 手当の記録（10月）')
            ->assertSee('本能寺のレクチャー')
            ->assertSeeInOrder(['<th>日付</th>', '<th>案件</th>', '企業様名', '<th>スタッフ</th>', '手当・メモ', '金額', '入れた人'], false)   // 並び（2026-10-13 baba）
            ->assertSee('株式会社テスト商事')
            ->assertSee('ym=2026-11&amp;tab=allowances', false)
            ->assertDontSee('📁 請求書PDFの置き場所');

        // 消せる。
        $id = StaffAllowance::where('kind', '運転手当')->value('id');
        $this->post('/staff-allowances/'.$id.'/delete')->assertRedirect();
        $this->assertSame(0, StaffAllowance::where('kind', '運転手当')->count());
    }

    public function test_rules(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $s = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        $emp = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);

        $this->actingAsPerson($me);
        // 決まっていない種類は入れられない。
        $this->postJson('/staff-allowances', ['staff_id' => $s->id, 'date' => '2026-10-12', 'kind' => '謎の手当'])->assertStatus(422);
        // その他は金額が要る。
        $this->postJson('/staff-allowances', ['staff_id' => $s->id, 'date' => '2026-10-12', 'kind' => 'その他'])->assertStatus(422);
        // 社員には付けない（請求書を出さない）。
        $this->postJson('/staff-allowances', ['staff_id' => $emp->id, 'date' => '2026-10-12', 'kind' => '運転手当'])->assertStatus(422);
        $this->assertSame(0, StaffAllowance::count());
    }

    /**
     * 前日設営・リハ日のアサインは手当の記録に自動で入る／消したら入れ直さない／
     * 種類を切り替えられる（4時間以上のリハ＝1日分）／複数人にまとめて入れる（2026-10-13 baba）。
     */
    public function test_auto_rows_dismiss_switch_and_multi(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京', 'name' => '担当花子']);
        $a = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => '設営一郎']);
        $b = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京', 'name' => 'リハ二郎']);
        $prep = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-17', 'date_type' => '前日設営', 'project_name' => '運動会設営', 'format' => 'リアル']);
        $reh = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-10', 'date_type' => 'リハ日', 'project_name' => '鷹狩り', 'format' => 'オンライン']);
        Assignment::create(['project_id' => $prep->id, 'staff_id' => $a->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-17']);
        Assignment::create(['project_id' => $prep->id, 'staff_id' => $b->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-17']);
        Assignment::create(['project_id' => $reh->id, 'staff_id' => $b->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-10']);

        // 開くと自動で入る（何度開いても1回だけ）。
        $this->actingAsPerson($me)->get('/staff-invoice-check?ym=2026-10&tab=allowances')->assertOk();
        $this->get('/staff-invoice-check?ym=2026-10&tab=allowances')->assertOk()->assertSee('si-auto', false);
        $this->assertSame(3, StaffAllowance::whereNotNull('auto_key')->count());
        $this->assertSame(2, StaffAllowance::where('kind', '前日設営手当')->count());
        $this->assertSame(1, StaffAllowance::where('kind', '事前リハーサル手当')->count());

        // 消したら隠れ、開き直しても入れ直さない。
        $rowA = StaffAllowance::where('staff_id', $a->id)->first();
        $this->post('/staff-allowances/'.$rowA->id.'/delete')->assertRedirect();
        $this->get('/staff-invoice-check?ym=2026-10&tab=allowances')->assertOk();
        // はじめに出るのは請求書チェック（2026-10-14 baba「請求書チェックを前に戻す」）。
        $this->get('/staff-invoice-check?ym=2026-10')->assertOk()->assertSeeInOrder(['class="on"', '🧾 請求書チェック', '✍ 手当の記録'], false);
        $this->assertNotNull($rowA->fresh()->dismissed_at);
        $this->assertSame(3, StaffAllowance::count(), '消した印として残る＝入れ直さない');

        // 種類を切り替える：4時間以上のリハ＝1日分（オンラインの案件なので6,000円）。
        $rowReh = StaffAllowance::where('kind', '事前リハーサル手当')->first();
        $this->post('/staff-allowances/'.$rowReh->id.'/kind', ['kind' => StaffAllowances::SIX_HOURS])->assertRedirect();
        $this->assertSame(6000, $rowReh->fresh()->amount);
        $this->get('/staff-invoice-check?ym=2026-10&tab=check')->assertOk()
            ->assertSee('オンラインイベント（4時間以上のリハ＝1日分）：10/10')
            ->assertSee('前日設営手当：10/17');

        // 複数人にまとめて入れる（宿泊手当を2人に）。
        $this->post('/staff-allowances', ['staff_ids' => [$a->id, $b->id], 'project_id' => $prep->id, 'date' => '2026-10-17',
            'kind' => '宿泊手当', 'note' => '後泊'])->assertRedirect()->assertSessionHas('ok', '宿泊手当を2人に入れました。');
        $this->assertSame(2, StaffAllowance::where('kind', '宿泊手当')->count());
        // 大型手当は1回2,000円（2026-10-13 baba）。金額を入れなくてよい。
        $this->postJson('/staff-allowances', ['staff_id' => $a->id, 'date' => '2026-10-18', 'kind' => '大型手当'])
            ->assertOk()->assertJsonPath('item.amount', 2000);

        // メモはあとから書ける（自動で入った行にも）。
        $auto = StaffAllowance::where('kind', '前日設営手当')->whereNull('dismissed_at')->first();
        $this->postJson('/staff-allowances/'.$auto->id.'/note', ['note' => '7時から設営'])->assertOk();
        $this->assertSame('7時から設営', $auto->fresh()->note);

        // 収支入力の画面（案件ごと）も、消した行は出さない・自動の印が付く。
        $this->getJson('/staff-allowances/project?project='.$prep->id)->assertOk()
            ->assertJsonCount(3, 'items')   // 設営（リハ二郎・自動）＋宿泊×2
            ->assertJsonPath('items.0.auto', true);
    }

    /**
     * ARENA場所貸しはD手当（2026-10-13 baba「場所貸しはD手当だと思う」）。
     * 「ARENA場所貸し(ライブリハ)」が取込でリハ日になっていても、事前リハーサル手当にしない。
     * 前の決まりで入ってしまった自動の行（誰も触っていない）は、開いたときに直る。
     */
    public function test_arena_rental_gets_director_allowance(): void
    {
        $me = PersonFactory::new()->create(['role' => 'employee', 'office' => '東京']);
        $s = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        $p = ProjectFactory::new()->create(['office' => '東京', 'start_date' => '2026-10-20', 'date_type' => 'リハ日',
            'project_name' => 'ARENA場所貸し(ライブリハ)', 'content_names' => ['ARENA場所貸し(ライブリハ)']]);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s->id, 'role' => 'D', 'status' => '確定', 'date' => '2026-10-20']);
        // 前の決まりで入っていた行（誰も触っていない）。
        StaffAllowance::create(['staff_id' => $s->id, 'project_id' => $p->id, 'date' => '2026-10-20', 'kind' => '事前リハーサル手当',
            'count' => 1, 'auto_key' => 'auto:'.$p->id.':'.$s->id.':2026-10-20', 'created_by' => '自動（アサイン）']);

        // 同じ場所貸しにFCで入った人（2026-10-13 baba「FCだったらD手当無い」）＋前の決まりで入っていた行。
        $fc = PersonFactory::new()->create(['role' => 'staff', 'office' => '東京']);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $fc->id, 'role' => 'FC', 'status' => '確定', 'date' => '2026-10-20']);
        StaffAllowance::create(['staff_id' => $fc->id, 'project_id' => $p->id, 'date' => '2026-10-20', 'kind' => '事前リハーサル手当',
            'count' => 1, 'auto_key' => 'auto:'.$p->id.':'.$fc->id.':2026-10-20', 'created_by' => '自動（アサイン）']);

        $this->actingAsPerson($me)->get('/staff-invoice-check?ym=2026-10&tab=allowances')->assertOk();
        $this->assertSame(['ディレクター手当'], StaffAllowance::pluck('kind')->all(), 'Dの人だけD手当・FCの行は取り除く');
        $this->assertSame($s->id, StaffAllowance::value('staff_id'));
        $this->assertSame(5000, StaffAllowances::unit(StaffAllowance::first()));

        // 取込でも、場所貸しの名前は「リハ日」と読まない。
        $this->assertNull(\App\Support\ScheduleMark::detect('ARENA場所貸し(ライブリハ)'));
        $this->assertNotNull(\App\Support\ScheduleMark::detect('綱引き大会(リハ)'));
    }
}
