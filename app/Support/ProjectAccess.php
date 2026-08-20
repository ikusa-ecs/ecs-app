<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

/**
 * 「この案件を書き換えていいか」を判定する共通ルール（保存処理のための拠点チェック）。
 *
 * ■ なぜ必要か
 *   画面の一覧は OfficeScope で拠点ごとに絞れているが、**保存処理は案件の持ち主を見ていなかった**。
 *   そのため URL やリクエストに他拠点の案件IDを直接書けば、一般社員でも他拠点の案件を
 *   書き換えられる状態だった（アサイン保存・ピックアップ・D決め・アサイン表・案件一覧のセル・
 *   公開ボードの各設定・エントリー切替の7か所）。ここを1か所にまとめて塞ぐ。
 *
 * ■ ルール（2026-08-20・収支の FinanceAccess::canEdit と同じ考え方）
 *   ・管理者・Administrator（manager/admin）＝全拠点OK
 *   ・一般社員（employee）＝自分の拠点の案件＋その拠点に共有（project_shares）された案件だけ
 *   ・スタッフ（staff）＝不可（そもそもこれらの画面に入れない）
 *   ・案件に拠点が入っていない場合は「東京」あつかい（OfficeScope::DEFAULT_OFFICE と同じ）
 *
 * ■ 使い方
 *   $err = ProjectAccess::denyJson($project);   // JSONで返す画面（fetch）
 *   if ($err) { return $err; }
 *   ProjectAccess::authorize($project);         // 画面遷移する画面（403で止める）
 */
class ProjectAccess
{
    /** この人はこの案件を書き換えていいか。 */
    public static function canEdit(?Project $project, ?Person $me = null): bool
    {
        if (! $project) {
            return false;
        }
        $me = $me ?? Auth::user();
        if (! $me) {
            return false;
        }

        // 管理者以上は全拠点OK（他拠点の応援・巻き取りを面倒なく直せるようにする）。
        if (in_array($me->permission ?? '', ['manager', 'admin'], true)) {
            return true;
        }

        // スタッフはこれらの保存処理を使わない。
        if (($me->role ?? '') === 'staff') {
            return false;
        }

        $mine = trim((string) ($me->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
        $owner = trim((string) ($project->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
        if ($owner === $mine) {
            return true;
        }

        // 自分の拠点に共有（ヘルプ・巻き取り）されている案件も直してよい。
        return $project->shares()->where('office', $mine)->exists();
    }

    /**
     * JSONで返す画面用。書き換えてよければ null、だめなら 403 のレスポンスを返す。
     * 呼び出し側は `if ($deny = ProjectAccess::denyJson($project)) { return $deny; }` と書く。
     */
    public static function denyJson(?Project $project, ?Person $me = null)
    {
        if (self::canEdit($project, $me)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'message' => 'この案件は他の拠点の案件です。編集できるのは、その拠点の担当者と管理者以上だけです。',
        ], 403);
    }

    /** 画面遷移する画面用。書き換えてよくなければ 403 で止める。 */
    public static function authorize(?Project $project, ?Person $me = null): void
    {
        if (! self::canEdit($project, $me)) {
            abort(403, 'この案件は他の拠点の案件です。編集できるのは、その拠点の担当者と管理者以上だけです。');
        }
    }
}
