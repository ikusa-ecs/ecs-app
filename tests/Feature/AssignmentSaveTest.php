<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * アサイン保存（POST /project-assign/save）の結合テスト。テスト仕様書 IT-ASGN-01 / IT-ASGN-02。
 *
 * ・RefreshDatabase：テストごとにメモリ上のDBをまっさらにする（本番/開発データは触らない）。
 * ・actingAsPerson()：ログイン＋メール2段階認証(OTP)確認済みの状態を作る（使わないと /otp に飛ばされる）。
 *
 * save() の要点（AssignmentController@save）：
 *   ・入力＝project_id（必須）／status（'仮' か '確定'）／staff_ids（配列）／role（staff_id => 役割コード）。
 *   ・対象日は案件の start_date。案件×その日を「いま選ばれている人」で上書き保存する（外した人は消える）。
 *   ・役割は AssignmentRole::isValid() を通るコードだけ保存。無効値は '' になる。
 */
class AssignmentSaveTest extends TestCase
{
    use RefreshDatabase;

    /** IT-ASGN-01：社員がスタッフ2名を保存すると assignments に2行・役割も正しく保存される。 */
    public function test_employee_can_save_assignments(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staffA   = PersonFactory::new()->staff()->create();
        $staffB   = PersonFactory::new()->staff()->create();

        $res = $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id, $staffB->id],
            'role'       => [
                $staffA->id => 'OP',   // 音響（有効コード）
                $staffB->id => 'MC',   // 司会進行（有効コード）
            ],
        ]);

        $res->assertRedirect('/project-assign?project=' . urlencode($project->id));

        // その案件×その日で2行保存されている。
        $rows = Assignment::where('project_id', $project->id)->get();
        $this->assertCount(2, $rows);

        // 日付は時刻付き保存に備え、モデルの date キャストを Y-m-d に整形して比較する。
        foreach ($rows as $row) {
            $this->assertSame('2026-09-01', $row->date->format('Y-m-d'));
            $this->assertSame('確定', $row->status);
        }

        // 役割が本人ごとに正しく入っている。
        $this->assertSame('OP', Assignment::where('project_id', $project->id)
            ->where('staff_id', $staffA->id)->value('role'));
        $this->assertSame('MC', Assignment::where('project_id', $project->id)
            ->where('staff_id', $staffB->id)->value('role'));
    }

    /**
     * IT-ASGN-02：同じ案件×同じ日を保存し直すと、外した人が消えて最後の顔ぶれだけ残る（上書き）。
     *
     * 【日付の扱いについて（テスト側の調整）】
     * save() は「その案件×その日」を消してから入れ直す。この削除は
     *   Assignment::where('project_id', ...)->where('date', $date)->delete()
     * で、$date は案件開催日を Y-m-d（日付のみ）にした文字列。
     * 本番の MySQL は date 型カラムに「日付のみ」で入るのでこの削除は一致するが、
     * テスト用のメモリ SQLite は date 型が無く、モデルの date キャストが
     * 「2026-09-01 00:00:00」（時刻付き）で保存するため、where('date','2026-09-01') が
     * 1件も一致せず削除が空振りする（＝2回続けて画面保存すると unique 制約で落ちる）。
     * ＝ SQLite 特有の保存形式の差。コントローラは変更しない方針なので、ここでは
     * 「すでに保存済み（＝本番と同じ日付のみ形式）の3名」を先に用意し、そこへ
     * 1名だけの保存を1回流して “上書きで外した人が消える” 挙動を検証する。
     */
    public function test_saving_replaces_previous_members(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staffA   = PersonFactory::new()->staff()->create();
        $staffB   = PersonFactory::new()->staff()->create();
        $staffC   = PersonFactory::new()->staff()->create();

        // 事前状態：この案件×この日に、すでに3名が保存されている（本番 MySQL と同じ「日付のみ」で用意）。
        foreach ([$staffA, $staffB, $staffC] as $s) {
            \Illuminate\Support\Facades\DB::table('assignments')->insert([
                'project_id' => $project->id,
                'staff_id'   => $s->id,
                'date'       => '2026-09-01',
                'role'       => '',
                'status'     => '仮',
            ]);
        }
        $this->assertSame(3, Assignment::where('project_id', $project->id)->count());

        // 保存：1名だけで保存し直す（＝外した2名は消えるはず）。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id],
            'role'       => [$staffA->id => 'D'],
        ])->assertRedirect('/project-assign?project=' . urlencode($project->id));

        // その案件×その日で残っているのは1行だけ＝残した本人。
        $rows = Assignment::where('project_id', $project->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($staffA->id, $rows->first()->staff_id);
        $this->assertSame('D', $rows->first()->role);
        $this->assertSame('確定', $rows->first()->status);
        $this->assertSame('2026-09-01', $rows->first()->date->format('Y-m-d'));
    }

    /**
     * 不具合Bの再発防止：同じ案件×同じ日に、画面から2回続けて保存し直しても 500 にならない。
     * 修正前は save() の削除が where('date',$date)（時刻付き保存と不一致）で空振りし、
     * 2回目の insert が unique 制約に衝突して 500 になっていた。whereDate への統一で解消。
     */
    public function test_resaving_same_project_and_day_does_not_error(): void
    {
        $employee = PersonFactory::new()->create();
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staffA   = PersonFactory::new()->staff()->create();
        $staffB   = PersonFactory::new()->staff()->create();

        // 1回目：2名を保存。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id, $staffB->id],
            'role'       => [],
        ])->assertRedirect('/project-assign?project=' . urlencode($project->id));

        // 2回目：同じ案件×同じ日に1名だけで保存し直す（修正前はここで 500）。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id],
            'role'       => [$staffA->id => 'D'],
        ])->assertRedirect('/project-assign?project=' . urlencode($project->id));

        // 最終的にその案件×その日は1名だけ残る。
        $this->assertSame(1, Assignment::where('project_id', $project->id)->count());
        $this->assertSame($staffA->id, Assignment::where('project_id', $project->id)->value('staff_id'));
    }

    /**
     * D決め画面（/assign-director）で決めた社員のD／SD／FCは、アサイン保存で消えない（案A・baba 2026-08-06）。
     *
     * この画面の一覧に出るのはスタッフだけなので、社員の行まで消すとチェックの付け直しで復活できず
     * 「アサインを保存したらDが消えた」事故になっていた。スタッフの上書きは今までどおり効く。
     */
    public function test_save_keeps_director_rows_decided_by_employees(): void
    {
        $employee = PersonFactory::new()->create();
        $director = PersonFactory::new()->create();          // 社員＝D決め画面で D になった人
        $project  = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $staffA   = PersonFactory::new()->staff()->create();
        $staffB   = PersonFactory::new()->staff()->create();

        // D決め画面が保存した状態を作る（role='D'・社員）。
        Assignment::create([
            'project_id' => $project->id, 'staff_id' => $director->id,
            'date' => '2026-09-01', 'role' => 'D', 'status' => '仮',
        ]);
        // スタッフ2名も先に入っている状態にする。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id, $staffB->id],
            'role'       => [$staffA->id => 'OP', $staffB->id => 'MC'],
        ]);

        // Dはそのまま残っている（消えない）。
        $this->assertSame('D', Assignment::where('project_id', $project->id)
            ->where('staff_id', $director->id)->value('role'), 'D決めで決めたDが消えている');

        // スタッフBを外して保存し直す＝スタッフの上書きは今までどおり効く。
        $this->actingAsPerson($employee)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$staffA->id],
            'role'       => [$staffA->id => 'OP'],
        ]);

        $left = Assignment::where('project_id', $project->id)->pluck('staff_id')->all();
        $this->assertEqualsCanonicalizing([$director->id, $staffA->id], $left);
    }

    /** 権限：スタッフはアサイン保存できず、自分のスタッフ画面へ戻される（行も増えない）。 */
    public function test_staff_cannot_save_assignments(): void
    {
        $staff   = PersonFactory::new()->staff()->create();
        $project = ProjectFactory::new()->create(['start_date' => '2026-09-01']);
        $target  = PersonFactory::new()->staff()->create();

        $this->actingAsPerson($staff)->post('/project-assign/save', [
            'project_id' => $project->id,
            'status'     => '確定',
            'staff_ids'  => [$target->id],
            'role'       => [],
        ])->assertRedirect('/staff-portal');

        $this->assertSame(0, Assignment::count());
    }
}
