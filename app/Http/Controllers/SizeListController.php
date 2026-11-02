<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * サイズ一覧（/sizes）。2026-11-02 スタッフ要望「常勤が靴と服のサイズだけ見られるページが欲しい」。
 *
 * 【なぜ作ったか】サイズはスタッフ名簿の「詳細」を1人ずつ開かないと見られず、衣装の準備で全員ぶんを見比べにくかった。
 * 【出すもの】在籍中のスタッフと社員の、名前・拠点・種類・靴（足袋）・服・身長だけ。ほかの個人情報は出さない。
 * 【見られる人】社員以上＋スタッフ名簿で「👕 サイズ一覧を見られる」にチェックされたスタッフ（people.can_view_sizes）。
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

        return in_array($me->permission, ['employee', 'manager', 'admin'], true) || (bool) $me->can_view_sizes;
    }

    public function index(Request $request)
    {
        abort_unless(self::canView(Auth::user()), 403);

        $office = OfficeScope::filter($request);
        $rows = OfficeScope::applyToPeople(Person::query()->whereIn('role', ['staff', 'employee']), $office)
            ->where(fn ($q) => $q->where('active', true)->orWhereNull('active'))
            ->byKana()
            ->get(['id', 'name', 'name_kana', 'role', 'office', 'shoe_size', 'shirt_size', 'height'])
            ->map(fn (Person $p) => [
                'name' => (string) $p->name,
                'kana' => (string) ($p->name_kana ?? ''),
                'kind' => $p->role === 'staff' ? 'スタッフ' : '社員',
                'office' => (string) ($p->office ?? ''),
                'shoe' => trim((string) ($p->shoe_size ?? '')),
                'wear' => trim((string) ($p->shirt_size ?? '')),
                'height' => trim((string) ($p->height ?? '')),
            ]);

        return view('sizes', ['rows' => $rows]);
    }
}
