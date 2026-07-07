<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * マイページ（/mypage）の動作確認用の見本データ。
 *
 * ・ログイン中の社員「baba（E-007・イベプラ）」を people に追加する。
 *   （※メールはダミー e-007@example.com。実在の @ikusa.co.jp は使わない＝本物の自己登録と衝突しない）
 * ・baba を「現場メンバー」として数件の案件にアサインする（① アサインされた案件）。
 * ・baba を営業担当にした案件を作る（② 営業担当の案件）。
 *
 * ・updateOrCreate（各テーブルのキー）で書くので、何度流しても重複せず・他の行も消さない。
 * ・登録は `php artisan db:seed --class=DemoMyPageSeeder`（DatabaseSeeder には登録しない＝
 *   migrate:fresh 時に自動では入らない見本専用シーダー）。
 */
class DemoMyPageSeeder extends Seeder
{
    public function run(): void
    {
        // ── ① ログイン中の社員「baba」（イベプラ）──
        $me = Person::updateOrCreate(['id' => 'E-007'], [
            'role' => 'employee',
            'name' => 'baba',
            'department' => 'イベプラ',
            'email' => 'e-007@example.com',   // ダミー（実在の @ikusa.co.jp は使わない）
            'hire_date' => '2020-04-01',
            'active' => true,
            'is_admin' => true,
            'permission' => 'admin',       // Administrator（全権）
            'password' => 'password',      // 開発用の仮パスワード（本番前に入れ替え・castで自動暗号化）
        ]);

        // ── ② baba のアサイン（案件ID → 自分のポジション）──
        // 案件が DB に在るものだけ入れる（無い ID はスキップ）。日付は案件の開催日に合わせる。
        // 役割は必ず正本（AssignmentRole）のコードで作る＝「ディレクター」等の別表記を入れない。
        $myAssigns = [
            ['undo_d1', AssignmentRole::D],    // 今月・大型
            ['konshin', AssignmentRole::SD],   // 今月
            ['shinkan', AssignmentRole::MC],   // 今月
            ['bousai', AssignmentRole::D],     // 翌月（月絞り込みの確認用）
            ['past_fes', AssignmentRole::D],   // 過去（アーカイブの確認用）
        ];
        foreach ($myAssigns as [$pid, $role]) {
            $project = Project::find($pid);
            if (! $project || ! $project->start_date) {
                continue;
            }
            Assignment::updateOrCreate(
                ['project_id' => $pid, 'staff_id' => $me->id, 'date' => $project->start_date->format('Y-m-d')],
                ['role' => $role, 'status' => '確定', 'assigned_at' => Carbon::now()],
            );
        }

        // ── ③ baba が営業担当の案件（② 営業担当の案件 セクションに出す）──
        // cases.js 形では sales = sales_owners[0]。マイページ②は c.sales === ME（=baba）で突き合わせる。
        foreach (['board', 'mizu', 'enni1'] as $pid) {
            $project = Project::find($pid);
            if ($project) {
                $project->update(['sales_owners' => ['baba']]);
            }
        }
    }
}
