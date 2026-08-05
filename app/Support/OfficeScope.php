<?php

namespace App\Support;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 拠点の「表示範囲」を決める共通部品（全拠点運用・設計書19.2(1)）。
 *
 * ルール：
 *  ・管理者・Administrator（manager/admin）＝全拠点を見られる。画面上のスイッチで拠点を選べる。
 *      選択なし＝全拠点（filter が null）。
 *  ・一般社員・スタッフ（employee/staff）＝自分の拠点だけ。スイッチは出さず、常に自拠点で固定。
 *
 * 現状は全員が東京なので、絞っても絞らなくても見える案件は同じ（＝実害なく仕組みだけ入る）。
 */
class OfficeScope
{
    /** 管理者以上か（＝全拠点を見られる・スイッチを出す対象か）。 */
    public static function canSeeAll(): bool
    {
        $perm = Auth::user()->permission ?? 'staff';

        return in_array($perm, ['manager', 'admin'], true);
    }

    /**
     * 実際に絞り込む拠点名を返す。null＝全拠点（絞らない）。
     *  ・管理者以上：スイッチで選んだ拠点（?office=）。未選択は null＝全拠点。
     *  ・それ以外  ：自分の拠点で固定（未設定なら東京）。
     */
    public static function filter(Request $request): ?string
    {
        if (self::canSeeAll()) {
            $sel = trim((string) $request->query('office', ''));

            return $sel === '' ? null : $sel;
        }

        return Auth::user()->office ?: '東京';
    }

    /** スイッチのハイライト用に「今選ばれている値」を返す（''＝全拠点）。管理者以上のみ意味を持つ。 */
    public static function selected(Request $request): string
    {
        return trim((string) $request->query('office', ''));
    }

    /** スイッチに並べる拠点の選択肢（有効な拠点・並び順）。 */
    public static function options(): array
    {
        return Office::where('active', true)->orderBy('sort_order')->pluck('name')->all();
    }

    /**
     * 案件（projects）を拠点で絞る。$office が null なら何もしない（＝全拠点）。
     *
     * 「その拠点で登録された案件」＋「その拠点に共有（ヘルパ/巻き取り）された案件」を見せる。
     * ※ 同じ式を各画面にコピペすると片方だけ直して食い違うので、ここ1か所に置いて呼び出す。
     */
    public static function applyToProjects($query, ?string $office)
    {
        return $query->when($office, fn ($q) => $q->where(function ($qq) use ($office) {
            $qq->where('office', $office)
                ->orWhereHas('shares', fn ($s) => $s->where('office', $office));
        }));
    }

    /**
     * 人（people＝社員・スタッフ）を拠点で絞る。$office が null なら何もしない（＝全拠点）。
     *
     * $keepIds ＝拠点が違っても必ず残す人（例：すでにその案件へアサインされている他拠点のヘルプ）。
     *   理由：D決め・アサイン画面の保存は「いま画面に出ている人で上書き」なので、
     *        候補から消えた人は保存した瞬間に担当を外されてしまう。それを防ぐための逃げ道。
     *
     * ⚠ people.office が空の人は「東京」扱いにする（OfficeScope::filter の既定と同じ考え方）。
     *   実データ投入時に拠点を埋め直すまでの保険。
     *
     * @param  array<int|string, string>  $keepIds
     */
    public static function applyToPeople($query, ?string $office, array $keepIds = [])
    {
        return $query->when($office, fn ($q) => $q->where(function ($qq) use ($office, $keepIds) {
            $qq->where('office', $office);
            if ($office === '東京') {
                $qq->orWhereNull('office')->orWhere('office', '');
            }
            if ($keepIds) {
                $qq->orWhereIn('id', $keepIds);
            }
        }));
    }
}
