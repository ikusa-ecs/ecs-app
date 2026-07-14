<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Administrator（全権）専用の管理コンソール。
 *
 * 権限4段階のうち「Administrator だけができること」をこの1画面に集約する。
 *   ・権限変更（社員の昇格／降格＝この画面のメイン機能）
 *   ・共通設定（マスタ削除・システム設定）／アカウント発行 などへの入口（集約リンク）
 *
 * ルートは tier:admin で保護（管理者・社員には見えない）。
 */
class AdminConsoleController extends Controller
{
    /** 権限一覧を表示（社員ごとに現在の権限＋変更プルダウン）。 */
    public function index()
    {
        return view('admin_console', [
            'employees'      => Person::employees()->orderBy('id')->get(),
            'staffCount'     => Person::staff()->count(),
            'adminCount'     => Person::where('permission', 'admin')->count(),
            // 表示用ラベル・色はビューの @php に頼らずコントローラから渡す（Blade の @php 挙動に依存しない）
            'permLabels'     => ['employee' => '社員', 'manager' => '管理者', 'admin' => 'Administrator'],
            'permBadgeColor' => ['employee' => '#6e5b49', 'manager' => '#2c6ca0', 'admin' => '#b5673a'],
        ]);
    }

    /** 1人の権限を変更する。 */
    public function updatePermission(Request $request)
    {
        $data = $request->validate([
            'id'         => ['required', 'string', Rule::exists('people', 'id')],
            'permission' => ['required', Rule::in(['employee', 'manager', 'admin'])],
        ], [], [
            'permission' => '権限',
        ]);

        $me = Auth::user();
        $target = Person::findOrFail($data['id']);
        $new = $data['permission'];

        // 権限変更は社員のみ（スタッフは role=staff・権限=staff 固定）。
        if ($target->role !== 'employee') {
            return back()->with('admin_error', 'スタッフの権限は変更できません（権限変更は社員が対象です）。');
        }

        // 自分自身の Administrator 権限は外せない（自分を締め出す事故を防ぐ）。
        if ($target->getKey() === $me->getKey() && $new !== 'admin') {
            return back()->with('admin_error', '自分自身のAdministrator権限は外せません。他のAdministratorに依頼してください。');
        }

        // 最後の Administrator を降格させない（全権が誰もいなくなるのを防ぐ）。
        if ($target->permission === 'admin' && $new !== 'admin') {
            $adminCount = Person::where('permission', 'admin')->count();
            if ($adminCount <= 1) {
                return back()->with('admin_error', '最後のAdministratorの権限は外せません。先に別の人をAdministratorにしてください。');
            }
        }

        if ($target->permission === $new) {
            return back()->with('admin_status', "{$target->name} さんの権限は変わりませんでした（すでに同じ権限です）。");
        }

        $labels = ['employee' => '社員', 'manager' => '管理者', 'admin' => 'Administrator'];
        $old = $labels[$target->permission] ?? $target->permission;

        $target->permission = $new;
        $target->save();

        return back()->with('admin_status', "{$target->name} さんの権限を「{$old}」→「{$labels[$new]}」に変更しました。");
    }
}
