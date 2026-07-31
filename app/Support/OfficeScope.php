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
}
