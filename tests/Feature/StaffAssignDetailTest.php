<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ本人の「確定アサインの詳細」に、当日必要な情報が届くことを守るテスト。
 *
 * 以前は日付・案件名・時間・集合形式・会場だけで、持ち物・服装・注意事項は
 * DBの項目そのものが無く、タップしても「モックのためダミーです」と出るだけだった。
 */
class StaffAssignDetailTest extends TestCase
{
    use RefreshDatabase;

    /** 案件登録で入れた「持ち物・服装・注意事項・集合場所の詳細」が本人の画面まで届く。 */
    public function test_staff_sees_belongings_and_notes(): void
    {
        $me = PersonFactory::new()->staff()->create();
        $date = Carbon::today()->addDays(3)->format('Y-m-d');
        $p = ProjectFactory::new()->published()->create([
            'start_date'       => $date,
            'assembly_type'    => '駅',
            'assembly_detail'  => '東口改札を出て正面のバス停前',
            'staff_belongings' => "黒スーツ\n白シャツ",
            'staff_dresscode'  => '上下黒',
            'staff_notes'      => '会場内は飲食禁止',
        ]);
        Assignment::create([
            'project_id' => $p->id, 'staff_id' => $me->id,
            'date' => $date, 'role' => 'OP', 'role2' => 'FC', 'status' => '確定',
            'remark' => '受付の締めをお願いします',
        ]);

        $row = collect(
            $this->actingAsPerson($me)->get('/staff-portal')->assertOk()
                ->original->getData()['published']
        )->firstWhere('id', $p->id);

        $this->assertNotNull($row);
        // 2026-08-21：4欄（集合場所の詳細／服装／持ち物／注意事項）を1欄にまとめたので、
        // 本人の画面に届くのは staff_notes（スタッフに伝えること）だけになった。
        $this->assertSame('会場内は飲食禁止', $row['staffNotes']);
        $this->assertSame('受付の締めをお願いします', $row['myNote'], '担当が本人向けに書いた一言も届く');
        // 兼任（role2）も表示名で届く。表示名の正本は AssignmentRole なのでそこから引いて比べる。
        $this->assertSame(\App\Support\AssignmentRole::label('FC'), $row['myRole2']);
        $this->assertSame(\App\Support\AssignmentRole::label('OP'), $row['myRole']);
    }

    /** 公開ボードからも同じ項目を保存できる（公開直前にアサイン担当が書き足せる）。 */
    public function test_publish_board_can_save_staff_info(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p = ProjectFactory::new()->create(['start_date' => Carbon::today()->addDays(3)->format('Y-m-d')]);

        $this->actingAsPerson($emp)
            ->postJson('/assign-publish/staff-info', [
                'id'    => $p->id,
                'notes' => "南ゲートから入館\n服装は私服可／雨天決行",
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame("南ゲートから入館\n服装は私服可／雨天決行", Project::find($p->id)->staff_notes);
    }

    /** 空文字で送ったら「未入力」に戻る（null）。 */
    public function test_publish_board_clears_staff_info_when_blank(): void
    {
        $emp = PersonFactory::new()->create(['permission' => 'manager']);
        $p = ProjectFactory::new()->create([
            'start_date'  => Carbon::today()->addDays(3)->format('Y-m-d'),
            'staff_notes' => '黒スーツ',
        ]);

        $this->actingAsPerson($emp)
            ->postJson('/assign-publish/staff-info', ['id' => $p->id, 'notes' => '  '])
            ->assertOk();

        $this->assertNull(Project::find($p->id)->staff_notes);
    }
}
