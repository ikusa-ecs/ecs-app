<?php

namespace App\Support;

use App\Models\Person;
use Illuminate\Support\Facades\Auth;

/**
 * 「人の情報を直してよいか」の正本（2026-09-01 baba決定）。
 *
 * 【決まり】
 *  ・**自分の情報は自分で直せる。**
 *  ・**他人の情報を直せるのは管理者以上**（manager / admin）。
 *
 * 【なぜ要るか】
 * ⚠ それまでは逆になっていた。
 *   ・入社年月日は**初回の初期設定でしか入れられず、間違えても本人が直せなかった**
 *     （実際に「入社年月日のつもりで生年月日を入れてしまった」方が出た）。
 *   ・いっぽう名簿の編集（他人の氏名・所属・拠点・サイズ・入社年月日）は
 *     **一般社員でも直せた**（権限の制限が付いていなかった）。
 *
 * 【ここで扱うのは「その人自身の情報」だけ】
 * ⚠ スタッフ名簿の**できるポジション・NGペア・人柄メモ**は業務のメモなので、
 *   今までどおり社員以上が直せる（2026-09-01 baba選択）。
 *   アサイン担当が一般社員のことがあり、ここを絞ると仕事が止まるため。
 *
 * ⚠ 氏名だけは、さらに厳しく **Administrator だけ**（2026-08-28 baba選択）。
 *   呼び名は名簿・アサイン表・出勤のすべてに出るので、直せる人をもっと絞っている。
 *   その判定は PersonController 側にあり、ここでは扱わない。
 */
final class PersonAccess
{
    /** 自分か。 */
    public static function isSelf(?Person $target): bool
    {
        $me = Auth::user();

        return $me && $target && (string) $me->id === (string) $target->id;
    }

    /** その人の情報を直してよいか（自分＝OK／他人＝管理者以上）。 */
    public static function canEdit(?Person $target): bool
    {
        if (self::isSelf($target)) {
            return true;
        }

        return in_array(optional(Auth::user())->permission, ['manager', 'admin'], true);
    }

    /**
     * 直せないときの返事（画面はJSONを待っているので必ずJSONで返す）。
     * 直してよければ null。
     *
     * ⚠ 呼ぶ側は必ず `if ($deny = PersonAccess::denyJson($person)) { return $deny; }` の形で使う。
     *   ここを通さない保存の入口を作らないこと（ProjectAccess::denyJson と同じ考え方）。
     */
    public static function denyJson(?Person $target)
    {
        if (self::canEdit($target)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'message' => '他の方の情報を直せるのは管理者以上です。ご自身の情報はマイプロフィールから直せます。',
        ], 403);
    }
}
