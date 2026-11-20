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
 *   ・複数日の商談（開催日数が2以上）は、日数ぶんの案件を連日イベントとして作る（2026-11-20 小沼さん）。
 *     初日＋翌日…と連続した日付で、2日目からは parent_project_id に初日のIDを入れて結ぶ（案件登録の連日イベントと同じ結び方）。
 *     中身（参加人数なども）は各日に同じ値。日付が飛ぶ・人数が合計のときは人が登録後に直す。
 *     作る日数は SfOpportunityMap::preview の series_days（下見の注意書きと同じ数）。開催日が空・日数が多すぎるときは初日だけ。
 *     受信箱の project_id には初日（親）の案件を入れる。
 *   ・営業担当（sales_owners）：担当営業のメールから名簿で1人に決まればその人。
 *     マイページから本人が登録したときは、決まらなくても押した本人を入れる（$fallbackSalesOwner）。
 *     ⚠ 受信箱（管理者）からの登録では押した人を入れない（押した管理者が営業とは限らないため）。
 */
final class SfInboxRegister
{
    /**
     * @param  string|null  $fallbackSalesOwner  メールで営業担当が決まらないときに入れる名前（マイページから本人が押したときだけ渡す）
     * @return array{ok: bool, message: string, project_id?: string}
     */
    public static function register(SfInboxItem $item, ?string $chosenOffice, ?string $byPersonId, ?string $fallbackSalesOwner = null): array
    {
        return DB::transaction(function () use ($item, $chosenOffice, $byPersonId, $fallbackSalesOwner) {
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
            $fallback = trim((string) $fallbackSalesOwner);
            if (empty($attrs['sales_owners']) && $fallback !== '') {
                $attrs['sales_owners'] = [$fallback];
            }
            $attrs['id'] = self::nextProjectId($p['date']);

            $project = Project::create($attrs);

            // 2日目から（連日イベント）。⚠ 同じトランザクションの中で作る＝途中で失敗したら初日ごと取り消される。
            $seriesDays = max(1, (int) ($p['series_days'] ?? 1));
            $lastDate = $p['date'];
            for ($i = 1; $i < $seriesDays && $p['date'] !== null; $i++) {
                $date = Carbon::parse($p['date'])->addDays($i)->toDateString();
                $day = $attrs;
                $day['id'] = self::nextProjectId($date);
                $day['start_date'] = $date;
                $day['parent_project_id'] = $project->id;
                Project::create($day);
                $lastDate = $date;
            }

            $fresh->update([
                'status' => SfInboxItem::REGISTERED,
                'project_id' => $project->id,   // 初日（親）の案件
                'decided_by' => $byPersonId,
                'decided_at' => now(),
            ]);

            $message = "案件「{$project->project_name}」を登録しました（ID: {$project->id}）。";
            if ($seriesDays >= 2) {
                $message .= "連日イベントとして{$seriesDays}日ぶん（{$p['date']}〜{$lastDate}）作りました。日付や参加人数が違う日は、案件を開いて直してください。";
            }

            return ['ok' => true, 'message' => $message, 'project_id' => $project->id];
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
