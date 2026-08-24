<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Departments;
use App\Support\TempPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * アカウント発行（1人ずつ）。
 *
 * 運用：最初の名簿投入は CSV 一括（/person-import）で行い、以降 増える人は
 * この画面で1人ずつ「ログインできるアカウント」を発行する（管理者以上）。
 *
 * 発行時に仮パスワードを設定し、must_onboard=true を立てる。
 * 本人はその仮パスワードで初回ログインし、/onboarding でパスワード変更＋プロフィール入力を行う。
 *
 * 権限付与のルール（設計・権限4段階）：
 *   Administrator（admin）権限を付けられるのは Administrator のみ。
 *   管理者（manager）は staff / employee / manager まで発行できる。
 */
class AccountController extends Controller
{
    /** 発行フォームを表示。 */
    public function create(Request $request)
    {
        // 名簿の「＋社員を追加」「＋スタッフを招待」から来たときは、種別を最初から選んでおく
        // （どちらの名簿から来たかで決まるので、選び直させない）。
        $role = $request->query('role');

        return view('account_new', [
            'canGrantAdmin' => optional(Auth::user())->permission === 'admin',
            'defaultRole' => in_array($role, ['employee', 'staff'], true) ? $role : '',
        ]);
    }

    /** 入力を検証してアカウントを発行する。 */
    public function store(Request $request)
    {
        $isAdmin = optional(Auth::user())->permission === 'admin';

        // スタッフの権限は必ず「スタッフ」。画面でも自動でそう選ばれるが、
        // グレーアウトした欄はブラウザが送信しないため、届かないことがある（2026-08-21 の不具合）。
        // 画面側は直したが、届かなかったときにここで補っておく（同じ事故を二度起こさないため）。
        if ($request->input('role') === 'staff' && ! $request->filled('permission')) {
            $request->merge(['permission' => 'staff']);
        }

        // 付与できる権限（Administrator は admin まで／管理者は manager まで）
        $allowedPerms = $isAdmin
            ? ['staff', 'employee', 'manager', 'admin']
            : ['staff', 'employee', 'manager'];

        $validated = $request->validate([
            'role'          => ['required', Rule::in(['employee', 'staff'])],
            'name'          => ['required', 'string', 'max:255'],
            // ふりがなは任意（分からなければ本人が初回ログインの初期設定で入れる）。
            'name_kana'     => ['nullable', 'string', 'max:255'],
            'email'         => ['required', 'email', Rule::unique('people', 'email')],
            'permission'    => ['required', Rule::in($allowedPerms)],
            'office'        => ['nullable', 'string'],
            // 所属（社員のみ意味がある）。主な所属1つ＋兼務。
            'department'     => ['nullable', 'string', 'max:50'],
            'departments'    => ['nullable', 'array'],
            'departments.*'  => ['string', 'max:50'],
            // チャットワークID＝リマインドの宛先。数字だけ。
            'chatwork_id'    => ['nullable', 'string', 'max:32', 'regex:/^[0-9]+$/'],
            // 入社日は発行画面では聞かない（本人が初回ログインの初期設定で入れる・2026-08-24 baba）。
            // 名簿CSV取込など他の入口から届くことはあるので、受け取れる形は残しておく。
            'hire_date'     => ['nullable', 'date'],
            'temp_password' => ['nullable', 'string', 'min:6'],
        ], [
            'permission.in' => 'その権限を付与する権限がありません（Administrator権限を付けられるのはAdministratorだけです）。',
            'email.unique'  => 'このメールアドレスは既に使われています。',
            'chatwork_id.regex' => 'チャットワークIDは数字だけで入れてください。',
        ], [
            'role'          => '種別',
            'name'          => '氏名',
            'name_kana'     => 'ふりがな',
            'email'         => 'メールアドレス',
            'permission'    => '権限',
            'office'        => '事務所',
            'department'    => '主な所属',
            'departments'   => '兼務している所属',
            'chatwork_id'   => 'チャットワークID',
            'hire_date'     => '入社日',
            'temp_password' => '仮パスワード',
        ]);

        // 種別と権限の整合（スタッフは権限=staff 固定・社員に staff 権限は付けない）
        if ($validated['role'] === 'staff' && $validated['permission'] !== 'staff') {
            throw ValidationException::withMessages(['permission' => 'スタッフの権限は「スタッフ」になります。']);
        }
        if ($validated['role'] === 'employee' && $validated['permission'] === 'staff') {
            throw ValidationException::withMessages(['permission' => '社員には社員以上の権限を選んでください。']);
        }

        // 仮パスワード（未入力なら自動生成）※任意項目は $validated にキーが無いことがある
        $tempPassword = ($validated['temp_password'] ?? null) ?: TempPassword::make();

        // 社員番号（E-###）／スタッフ番号（S-###）を自動採番
        $prefix = $validated['role'] === 'employee' ? 'E-' : 'S-';
        $next = $this->maxIdNum($prefix) + 1;
        $id = $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);

        Person::create([
            'id'           => $id,
            'role'         => $validated['role'],
            'name'         => $validated['name'],
            'name_kana'    => ($validated['name_kana'] ?? null) ?: null,
            'email'        => $validated['email'],
            'permission'   => $validated['permission'],
            'office'       => ($validated['office'] ?? null) ?: null,
            'chatwork_id'  => ($validated['chatwork_id'] ?? null) ?: null,
            // 所属は社員のときだけ入れる（スタッフに部署の概念は無い）。
            'department'   => $validated['role'] === 'employee'
                ? (($validated['department'] ?? null) ?: null)
                : null,
            'departments'  => $validated['role'] === 'employee'
                ? Departments::normalize($validated['department'] ?? null, $validated['departments'] ?? [])
                : null,
            'hire_date'    => ($validated['hire_date'] ?? null) ?: null,
            'password'     => $tempPassword,   // モデルのキャストで自動ハッシュ化
            'must_onboard' => true,            // 初回ログインで初期設定へ誘導
            'active'       => true,
        ]);

        // 発行結果（メール＋仮パスワード）を本人に伝えられるよう画面に表示する。
        return redirect('/account-new')->with('issued', [
            'id'       => $id,
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $tempPassword,
        ]);
    }

    /** 指定プレフィックス（E-/S-）の既存IDの最大番号。無ければ0。 */
    private function maxIdNum(string $prefix): int
    {
        return (int) (Person::where('id', 'like', $prefix . '%')->get()
            ->map(fn ($p) => (int) preg_replace('/\D/', '', $p->id))
            ->max() ?? 0);
    }

}
