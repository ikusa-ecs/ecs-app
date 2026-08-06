<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 収支の「誰が入力・修正できるか」と「いつまでに入れるか」の共通ルール。
 *
 * 2026-08-06 baba確定：
 *   ・見る　＝社員以上は全案件の収支を見られる（名簿と同じ考え方）。
 *   ・直す　＝その案件の担当者本人（D＝ディレクター／営業担当）と、管理者以上だけ。
 *   ・締切　＝イベント終了後 3営業日以内（土日を飛ばす。祝日は未対応）。
 *
 * ※ これまで保存処理に持ち主のチェックが無く、社員なら他人の担当案件の収支も
 *   URLを直接指定すれば書き換えられる状態だった。ここを通して塞ぐ。
 */
class FinanceAccess
{
    /** 入力の締切＝イベント終了から何営業日後か。 */
    public const DEADLINE_BIZ_DAYS = 3;

    /**
     * この人はこの案件の収支を直せるか。
     *   ・管理者・Administrator（manager/admin）＝全案件OK
     *   ・その案件のD（assignments の role='D'・キャンセル除く）＝OK
     *   ・その案件の営業担当（projects.sales_owners に氏名が入っている）＝OK
     */
    public static function canEdit(Project $project, ?Person $me): bool
    {
        if (! $me) {
            return false;
        }

        if (in_array($me->permission ?? '', ['manager', 'admin'], true)) {
            return true;
        }

        // 営業担当（氏名で持っているため、空白を除いて照合する）
        $sales = is_array($project->sales_owners) ? $project->sales_owners : [];
        foreach ($sales as $name) {
            if (self::normName((string) $name) !== '' && self::normName((string) $name) === self::normName((string) $me->name)) {
                return true;
            }
        }

        // D（ディレクター）
        return Assignment::where('project_id', $project->id)
            ->where('staff_id', $me->id)
            ->where('role', 'D')
            ->where('status', '!=', 'キャンセル')
            ->exists();
    }

    /**
     * 入力の締切日（イベント日＝start_date の 3営業日後）。開催日が無い案件は null。
     * 「イベント終了後」なので、複数日案件でもその案件行の日付を起点にする（1案件1日の作りに合わせる）。
     */
    public static function deadline(Project $project): ?Carbon
    {
        if (! $project->start_date) {
            return null;
        }

        return self::addBusinessDays(Carbon::parse($project->start_date), self::DEADLINE_BIZ_DAYS);
    }

    /** 営業日をn日加算（土日を飛ばす。祝日は未対応＝人数確定リマインドと同じ考え方）。 */
    public static function addBusinessDays(Carbon $from, int $n): Carbon
    {
        $d = $from->copy()->startOfDay();
        $added = 0;
        while ($added < $n) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        return $d;
    }

    /** 氏名を照合用に正規化（全角/半角スペースを除去）。 */
    private static function normName(string $s): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', trim($s));
    }
}
