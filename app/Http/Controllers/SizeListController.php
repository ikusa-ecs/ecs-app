<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Departments;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * サイズ一覧（/sizes）。2026-11-02 スタッフ要望「常勤が靴と服のサイズだけ見られるページが欲しい」。
 *
 * 【なぜ作ったか】サイズはスタッフ名簿の「詳細」を1人ずつ開かないと見られず、衣装の準備で全員ぶんを見比べにくかった。
 * 【出すもの】在籍中のスタッフと社員の、名前・拠点・靴（足袋）・服・身長だけ。ほかの個人情報は出さない。
 * 【見られる人】社員以上＋**常勤**（people.is_fulltime・スタッフ名簿でチェック）の中で、
 *   **いま見せている**人（people.can_view_sizes・この画面で社員が付け外し）。
 *   2026-11-02 小沼さん「ずっと見るわけじゃないので、つど権限を付与する」＝常勤の印と、見せる／見せないを分けた。
 *   ⚠ 門は canView() の1か所。ルートは tier を付けずに、ここで止める。
 * 【拠点】OfficeScope（一般社員・スタッフは自拠点・管理者以上は切替あり）。
 */
class SizeListController extends Controller
{
    /** この画面を開けるか（正本）。スタッフ画面のリンクを出すかどうかにも使う。 */
    public static function canView(?Person $me): bool
    {
        if (! $me) {
            return false;
        }
        if (self::isEmployee($me)) {
            return true;
        }

        // 常勤の印が外れたら、見せる印が残っていても見られない。
        return (bool) $me->is_fulltime && (bool) $me->can_view_sizes;
    }

    private static function isEmployee(?Person $me): bool
    {
        return $me && in_array($me->permission, ['employee', 'manager', 'admin'], true);
    }

    public function index(Request $request)
    {
        $me = Auth::user();
        abort_unless(self::canView($me), 403);

        $office = OfficeScope::filter($request);
        $people = OfficeScope::applyToPeople(Person::query()->whereIn('role', ['staff', 'employee']), $office)
            ->where(fn ($q) => $q->where('active', true)->orWhereNull('active'))
            ->byKana()
            ->get(['id', 'name', 'name_kana', 'role', 'office', 'department', 'shoe_size', 'shirt_size', 'height', 'is_fulltime', 'can_view_sizes']);
        $row = fn (Person $p) => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'kana' => (string) ($p->name_kana ?? ''),
            'office' => (string) ($p->office ?? ''),
            'shoe' => trim((string) ($p->shoe_size ?? '')),
            'wear' => trim((string) ($p->shirt_size ?? '')),
            'height' => trim((string) ($p->height ?? '')),
        ];

        // スタッフと社員は分けて出す。社員は部署ごと＝イベプラを先頭に（2026-11-02 小沼さん）。
        // 部署のまとめ方と並びの正本＝App\Support\Departments::GROUPS（イベプラ／セールス／クリエイティブ／その他）。
        // スタッフは、サイズ（靴・服・身長のどれか）を登録している人だけ（2026-11-02 小沼さん）。社員は全員。
        $staff = $people->where('role', 'staff')->map($row)
            ->filter(fn ($r) => $r['shoe'] !== '' || $r['wear'] !== '' || $r['height'] !== '')->values();
        // 表に出ない（サイズ未登録の）スタッフの名前だけ。名前で探して出てこないとき「未登録」と分かるように（2026-11-02 小沼さん）。
        $unregistered = $people->where('role', 'staff')->map($row)
            ->filter(fn ($r) => $r['shoe'] === '' && $r['wear'] === '' && $r['height'] === '')
            ->map(fn ($r) => ['name' => $r['name'], 'kana' => $r['kana']])->values();
        $byGroup = $people->where('role', 'employee')->groupBy(fn (Person $p) => Departments::group($p->department) ?: Departments::OTHER);   // 部署が空の人も「その他」に（消さない）
        $employees = [];
        foreach (Departments::GROUPS as $g) {
            if ($byGroup->has($g)) {
                $employees[$g] = $byGroup->get($g)->map($row)->values();
            }
        }

        // 常勤のスタッフと「いま見せているか」（社員だけに出す＝ここで付け外しする）。
        $fulltime = self::isEmployee($me)
            ? $people->where('role', 'staff')->filter(fn (Person $p) => (bool) $p->is_fulltime)
                ->map(fn (Person $p) => ['id' => $p->id, 'name' => (string) $p->name, 'office' => (string) ($p->office ?? ''), 'on' => (bool) $p->can_view_sizes])
                ->values()
            : null;

        return view('sizes', ['staff' => $staff, 'employees' => $employees, 'fulltime' => $fulltime, 'unregistered' => $unregistered]);
    }

    /** 常勤のスタッフに、サイズ一覧を見せる／見せないを付け外しする（社員以上・押した瞬間に保存）。 */
    public function grant(Request $request)
    {
        abort_unless(self::isEmployee(Auth::user()), 403);
        $data = $request->validate([
            'id' => ['required', 'string'],
            'on' => ['required', 'boolean'],
        ]);
        $p = Person::staff()->whereKey($data['id'])->first();
        if (! $p || ! $p->is_fulltime) {
            return response()->json(['ok' => false, 'message' => '常勤のスタッフだけ選べます'], 422);
        }
        $p->can_view_sizes = (bool) $data['on'];
        $p->save();

        return response()->json(['ok' => true, 'on' => $p->can_view_sizes]);
    }
}
