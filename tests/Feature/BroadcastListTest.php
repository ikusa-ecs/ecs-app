<?php

namespace Tests\Feature;

use App\Support\BroadcastKind;
use App\Support\ProjectFieldLabels;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 配信・中継案件一覧（/broadcast-list）。2026-09-18・FBシート No.21（馬場さん）
 * 「配信と中継が有の場合だけ表示する、配信・中継案件一覧ページ。各案件に配信担当を設定できるように」
 *
 * 見張るのは5つ。
 *   ① 配信・中継の案件だけが出る（配信なしの案件は出さない）
 *   ② ⚠ ARENA場所貸しの「配信・中継＝あり」も出る（保存場所が別なので抜けやすい）
 *   ③ 配信担当は**全拠点の社員**から選べる
 *   ④ 外部業者は自由入力で残る（社員と両方入れてもよい）
 *   ⑤ 保存の入口は案件一覧と同じ1か所（POST /projects/cells）＝拠点チェックもそこで通る
 */
class BroadcastListTest extends TestCase
{
    use RefreshDatabase;

    private function emp()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function project(array $attrs)
    {
        return ProjectFactory::new()->create(array_merge([
            'office' => '東京',
            'start_date' => Carbon::today()->addDays(7)->format('Y-m-d'),
        ], $attrs));
    }

    private function rows($me)
    {
        return collect($this->actingAsPerson($me)->get('/broadcast-list')->assertOk()
            ->original->getData()['rows']);
    }

    /** ① 配信・中継の案件だけが出る。 */
    public function test_only_broadcast_projects_are_listed(): void
    {
        $me = $this->emp();
        $on = $this->project(['broadcast' => '配信']);
        $relay = $this->project(['broadcast' => '中継']);
        $off = $this->project(['broadcast' => 'なし']);
        $blank = $this->project(['broadcast' => null]);

        $ids = $this->rows($me)->pluck('id');

        $this->assertTrue($ids->contains($on->id), '配信の案件が出ていません');
        $this->assertTrue($ids->contains($relay->id), '中継の案件が出ていません');
        $this->assertFalse($ids->contains($off->id), '配信なしの案件が出てしまっています');
        $this->assertFalse($ids->contains($blank->id), '未設定の案件が出てしまっています');
    }

    /**
     * ② ⚠ ARENA場所貸しの「配信・中継＝あり」も出る。
     * 保存場所が arena_options の中（JSON）なので、片方だけ見ると丸ごと抜け落ちる。
     */
    public function test_arena_broadcast_is_listed_too(): void
    {
        $me = $this->emp();
        $arena = $this->project([
            'format' => 'ARENA場所貸し',
            'broadcast' => 'なし',
            'arena_options' => ['broadcast' => 'あり', 'mc' => 'なし'],
        ]);
        $arenaOff = $this->project([
            'format' => 'ARENA場所貸し',
            'broadcast' => 'なし',
            'arena_options' => ['broadcast' => 'なし'],
        ]);

        $rows = $this->rows($me);

        $row = $rows->firstWhere('id', $arena->id);
        $this->assertNotNull($row, 'ARENAの配信案件が出ていません');
        $this->assertSame('配信・中継', $row['kind']);
        $this->assertTrue($row['isArena']);
        $this->assertFalse($rows->pluck('id')->contains($arenaOff->id), 'ARENAで配信なしの案件が出ています');
    }

    /** 判定の正本が2か所とも見ていること（画面ごとに書き直さないための見張り）。 */
    public function test_broadcast_kind_is_the_single_source(): void
    {
        $this->assertSame('配信', BroadcastKind::label($this->project(['broadcast' => '配信'])));
        $this->assertSame('中継', BroadcastKind::label($this->project(['broadcast' => '中継'])));
        $this->assertSame('', BroadcastKind::label($this->project(['broadcast' => 'なし'])));
        $this->assertSame('配信・中継', BroadcastKind::label(
            $this->project(['broadcast' => 'なし', 'arena_options' => ['broadcast' => 'あり']])
        ));
    }

    /** ③ 配信担当のプルダウンには**全拠点の社員**が出る。 */
    public function test_employees_from_every_office_can_be_picked(): void
    {
        $me = $this->emp();
        $this->project(['broadcast' => '配信']);
        PersonFactory::new()->create(['name' => '名古屋の社員', 'office' => '名古屋', 'must_onboard' => false]);

        $employees = collect($this->actingAsPerson($me)->get('/broadcast-list')->assertOk()
            ->original->getData()['employees']);

        $this->assertTrue(
            $employees->contains(fn ($e) => str_contains($e['label'], '名古屋の社員')),
            '他拠点の社員が選べません'
        );
    }

    /** ⑤ 社員の配信担当を保存できる（入口は案件一覧と同じ POST /projects/cells）。 */
    public function test_saving_the_employee_owner(): void
    {
        $me = $this->emp();
        $p = $this->project(['broadcast' => '配信']);
        $owner = PersonFactory::new()->create(['name' => '配信 太郎', 'office' => '大阪', 'must_onboard' => false]);

        $this->actingAsPerson($me)->postJson('/projects/cells', [
            'id' => $p->id,
            'broadcast_owner_id' => $owner->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($owner->id, $p->fresh()->broadcast_owner_id);
        $this->assertSame('配信 太郎', $this->rows($me)->firstWhere('id', $p->id)['ownerName']);
    }

    /** ④ 外部業者は自由入力で残る。社員と両方入れてもよい。 */
    public function test_saving_an_outside_vendor(): void
    {
        $me = $this->emp();
        $p = $this->project(['broadcast' => '中継']);

        $this->actingAsPerson($me)->postJson('/projects/cells', [
            'id' => $p->id,
            'broadcast_owner_name' => 'ABC映像',
        ])->assertOk();

        $this->assertSame('ABC映像', $p->fresh()->broadcast_owner_name);
        $this->assertSame('ABC映像', $this->rows($me)->firstWhere('id', $p->id)['outside']);
    }

    /** 名簿にいないIDは受け付けない（担当なしに倒す）＝物品担当と同じ決まり。 */
    public function test_unknown_employee_id_becomes_empty(): void
    {
        $me = $this->emp();
        $p = $this->project(['broadcast' => '配信', 'broadcast_owner_id' => 'E-999']);

        $this->actingAsPerson($me)->postJson('/projects/cells', [
            'id' => $p->id,
            'broadcast_owner_id' => 'E-NOPE',
        ])->assertOk();

        $this->assertNull($p->fresh()->broadcast_owner_id);
    }

    /** 担当がまだ決まっていない件数を出す（この画面でいちばん見たい数字）。 */
    public function test_counts_projects_without_an_owner(): void
    {
        $me = $this->emp();
        $this->project(['broadcast' => '配信']);
        $this->project(['broadcast' => '中継', 'broadcast_owner_name' => 'ABC映像']);

        $data = $this->actingAsPerson($me)->get('/broadcast-list')->assertOk()->original->getData();

        $this->assertSame(2, $data['sumRows']);
        $this->assertSame(1, $data['sumNoOwner'], '担当が未定の件数が合いません');
    }

    /** ⚠ 案件に列を足したら、編集履歴の日本語名にも足す（載っていない列は履歴に残らない）。 */
    public function test_history_knows_the_new_columns(): void
    {
        $this->assertArrayHasKey('broadcast_owner_id', ProjectFieldLabels::LABELS);
        $this->assertArrayHasKey('broadcast_owner_name', ProjectFieldLabels::LABELS);
    }

    /** 左メニューから開ける（作ったのに入口が無い、を防ぐ）。 */
    public function test_the_menu_has_a_link(): void
    {
        $this->actingAsPerson($this->emp())->get('/projects')->assertOk()
            ->assertSee('/broadcast-list', false);
    }

    /** スタッフは入れない（社員以上の画面）。 */
    public function test_staff_cannot_open_it(): void
    {
        $staff = PersonFactory::new()->staff()->create(['must_onboard' => false]);

        $this->actingAsPerson($staff)->get('/broadcast-list')->assertRedirect();
    }
}
