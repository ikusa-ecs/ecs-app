<?php

namespace App\Support;

use App\Models\Office;
use App\Models\Project;
use App\Models\SfInboxItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SFの受信箱の1件を「案件として登録」する正本（2026-11-16）。
 *
 * 【決まり】
 *   ・projects が増えるのは、ここを通ったときだけ（受信箱に入っただけでは何も変えない）。
 *   ・取り込み待ちの商談だけ登録できる。登録ずみ・見送りの商談は二重に登録しない。
 *   ・拠点は SfOffice で読み替える。読み替えられない（要確認）ときは、画面で人が選んだ拠点を使う。
 *     選ばれていなければ登録しない（推測で東京などに入れない）。
 *   ・ID は既存と同じ P-西暦-連番（ProjectController::nextProjectId と同じ決まり）。
 *   ・登録直後は非公開・状態は「未着手」（CSV一括取込と同じ）。公開はボードで担当が行う。
 *   ・複数日の商談でも作るのは初日の1件だけ（分けるのは人・調査結果 A-1）。
 */
final class SfInboxRegister
{
    /**
     * @return array{ok: bool, message: string, project_id?: string}
     */
    public static function register(SfInboxItem $item, ?string $chosenOffice, ?string $byPersonId): array
    {
        return DB::transaction(function () use ($item, $chosenOffice, $byPersonId) {
            // 押した瞬間の状態で確かめ直す（2人が同時に押しても二重にならないように）
            $fresh = SfInboxItem::lockForUpdate()->find($item->id);
            if (! $fresh || $fresh->status !== SfInboxItem::WAITING) {
                return ['ok' => false, 'message' => 'この商談は取り込み待ちではないので登録できません（すでに登録ずみか、見送りになっています）。'];
            }
            if ($fresh->project_id && Project::whereKey($fresh->project_id)->exists()) {
                return ['ok' => false, 'message' => "この商談はすでに案件（{$fresh->project_id}）になっています。"];
            }

            $p = SfOpportunityMap::preview($fresh->payload ?? []);

            $office = $p['office'];
            $chosen = trim((string) $chosenOffice);
            if ($chosen !== '') {
                if (! self::isOffice($chosen)) {
                    return ['ok' => false, 'message' => "拠点「{$chosen}」はECSにありません。"];
                }
                $office = $chosen;
            }
            if ($office === null) {
                return ['ok' => false, 'message' => '拠点が読み取れませんでした（SFの値：'.($p['office_raw'] ?? '空欄').'）。拠点を選んでから登録してください。'];
            }

            $attrs = SfOpportunityMap::toProjectAttributes($p) + [
                'office' => $office,
                'date_type' => '本番',
                'is_recruiting' => true,
                'status' => '未着手',
                'staff_published' => false,
            ];
            $attrs['id'] = self::nextProjectId($p['date']);

            $project = Project::create($attrs);

            $fresh->update([
                'status' => SfInboxItem::REGISTERED,
                'project_id' => $project->id,
                'decided_by' => $byPersonId,
                'decided_at' => now(),
            ]);

            return ['ok' => true, 'message' => "案件「{$project->project_name}」を登録しました（ID: {$project->id}）。", 'project_id' => $project->id];
        });
    }

    /** ECSの拠点か（拠点マスタに有効な名前があればそれを正とし、無ければ6拠点で見る）。 */
    public static function isOffice(string $name): bool
    {
        $names = Office::where('active', true)->pluck('name')->all();
        if ($names === []) {
            $names = SfOffice::ECS_OFFICES;
        }

        return in_array($name, $names, true);
    }

    /** 案件IDを発番：P-YYYY-NNNN（年は開催日。未定なら今年）。⚠ ProjectController::nextProjectId と同じ決まり。 */
    private static function nextProjectId(?string $startDate): string
    {
        $year = $startDate ? Carbon::parse($startDate)->year : Carbon::now()->year;
        $prefix = 'P-'.$year.'-';
        $maxSeq = Project::where('id', 'like', $prefix.'%')->pluck('id')
            ->map(fn ($id) => (int) substr($id, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }
}
