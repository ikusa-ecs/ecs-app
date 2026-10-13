<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Project;
use App\Models\StaffAllowance;
use Illuminate\Support\Carbon;

/**
 * スタッフの手当の種類と単価・自動で入れる手当（正本・2026-10-13 baba）。単価は「【マニュアル】請求書作成（26年5月～）」。
 *
 * 【手当の記録に自動で入るもの】（2026-10-13 baba「前日設営・リハの手当は手当の記録に自動で入るように。削除もできるように」）
 *   アサインの日程種別が「前日設営」「リハ日」の日 → 前日設営手当／事前リハーサル手当 を1行（auto_key 付き）。
 *   ・消した行は dismissed_at を付けて隠す＝次に開いても入れ直さない。
 *   ・種類を切り替えられる（前日設営手当／事前リハーサル手当／1案件として数える＝6時間以上のリハ・設営）。
 *   ・アサインが無くなった自動の行は（消した印が無ければ）取り除く。
 * 【アサインから請求書の目安で自動に数えるもの】MC・宿泊（前泊）・ロング＝StaffInvoiceDraft。
 *   手当の記録に同じ日を入れても二重には数えない。
 * ⚠ 大型手当の金額はマニュアルに無い＝金額を手で入れる（決まったら KINDS に入れる）。
 */
final class StaffAllowances
{
    /** 6時間以上のリハ・設営を「1案件」として数える（基本報酬で数える）。 */
    public const SIX_HOURS = '1案件として数える（6時間以上）';

    /** 種類 => 1回の金額（null＝金額を手で入れる／SIX_HOURS は案件の実施形態から決める）。 */
    public const KINDS = [
        '運転手当' => 2000,        // 20分以上運転したとき
        'スライド手当' => 1000,    // 1案件ごと
        '事前レクチャー手当' => 2000,
        'MC手当' => 1000,
        '宿泊手当' => 2000,
        'ロング手当' => 2000,
        '前日設営手当' => 4000,
        '事前リハーサル手当' => 2000,
        self::SIX_HOURS => null,
        '大型手当' => null,
        'その他' => null,
    ];

    /** 自動で入れた行で切り替えられる種類（リハ・設営）。 */
    public const SWITCHABLE = ['前日設営手当', '事前リハーサル手当', self::SIX_HOURS];

    /** 種類ごとの説明（入力欄の横に出す）。 */
    public const HINTS = [
        '運転手当' => '20分以上運転したとき（1回2,000円）',
        'スライド手当' => 'スライドを担当（1案件1,000円）',
        '事前レクチャー手当' => '事前レクチャーをした日（1回2,000円）※動画視聴・事前ブリーフィング・体験会は対象外',
        'MC手当' => '1案件1,000円。※アサインの役割がMCの日は自動で数えます（同じ日に入れても二重にはなりません）',
        '宿泊手当' => '1回2,000円。※案件の宿泊が「前泊」の日は自動で数えます（後泊などアサインで分からない日に入れてください）',
        'ロング手当' => '1回2,000円。※実施形態がリアルロングの日は自動で数えます',
        '前日設営手当' => '1回4,000円。※日程種別が前日設営のアサインは、手当の記録に自動で入ります',
        '事前リハーサル手当' => '1回2,000円。※日程種別がリハ日のアサインは、手当の記録に自動で入ります',
        self::SIX_HOURS => '6時間以上のリハ・設営は1案件として数えます（基本報酬：リアル9,000円・オンライン6,000円。案件の実施形態で決まります）',
        '大型手当' => '金額を入れてください（1回の金額）',
        'その他' => '金額とメモを入れてください',
    ];

    /** 金額を手で入れる種類（大型手当・その他）。 */
    public static function needsAmount(string $kind): bool
    {
        return array_key_exists($kind, self::KINDS) && self::KINDS[$kind] === null && $kind !== self::SIX_HOURS;
    }

    /** 「1案件として数える」の金額＝案件の実施形態の基本報酬（分からなければリアル）。 */
    public static function sixHoursAmount(?Project $p): int
    {
        $code = ProjectFormats::countCode($p ? (string) ($p->format ?? '') : '');

        return StaffInvoiceDraft::BASE[$code === 'online' ? 'オンライン' : 'リアル'];
    }

    /** 1回の金額（金額を入れる種類・1案件として数えるは、入っている金額）。 */
    public static function unit(StaffAllowance $a): int
    {
        return (int) (self::KINDS[$a->kind] ?? $a->amount ?? 0);
    }

    /**
     * アサインから「前日設営・リハ」の手当を手当の記録に入れる（何度呼んでも同じ結果）。
     * 消した印のある行は入れ直さない。アサインが無くなった自動の行（消した印なし）は取り除く。
     *
     * @param  list<string>|null  $staffIds  対象のスタッフ（null＝全員）
     * @param  string|null  $projectId  1案件だけ（収支入力の画面から）
     */
    public static function syncAuto(Carbon $from, Carbon $to, ?array $staffIds = null, ?string $projectId = null): void
    {
        $asg = Assignment::query()
            ->whereDate('date', '>=', $from->format('Y-m-d'))->whereDate('date', '<=', $to->format('Y-m-d'))
            ->where('status', '!=', 'キャンセル')
            ->when($staffIds !== null, fn ($q) => $q->whereIn('staff_id', $staffIds))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->get(['project_id', 'staff_id', 'date']);
        $projects = Project::whereIn('id', $asg->pluck('project_id')->unique())
            ->get(['id', 'date_type', 'is_cancelled', 'format'])->keyBy('id');
        $staff = \App\Models\Person::staff()->whereIn('id', $asg->pluck('staff_id')->unique())->pluck('id')->flip();

        $want = [];
        foreach ($asg as $a) {
            $p = $projects->get($a->project_id);
            if (! $p || $p->is_cancelled || ! isset($staff[$a->staff_id])) {
                continue;
            }
            $kind = match ((string) $p->date_type) {
                '前日設営' => '前日設営手当',
                'リハ日' => '事前リハーサル手当',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $date = Carbon::parse($a->date)->format('Y-m-d');
            $want['auto:'.$a->project_id.':'.$a->staff_id.':'.$date] = [$a->staff_id, $a->project_id, $date, $kind];
        }

        // いま入っている自動の行（この範囲・この人／この案件）。
        $have = StaffAllowance::whereNotNull('auto_key')
            ->whereDate('date', '>=', $from->format('Y-m-d'))->whereDate('date', '<=', $to->format('Y-m-d'))
            ->when($staffIds !== null, fn ($q) => $q->whereIn('staff_id', $staffIds))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->get()->keyBy('auto_key');

        foreach ($want as $key => [$sid, $pid, $date, $kind]) {
            if ($have->has($key)) {
                continue;   // もう入っている（消した印があっても入れ直さない）
            }
            StaffAllowance::create(['staff_id' => $sid, 'project_id' => $pid, 'date' => $date, 'kind' => $kind,
                'count' => 1, 'auto_key' => $key, 'created_by' => '自動（アサイン）']);
        }
        // アサインが無くなった自動の行は取り除く（消した印のある行は、印として残す）。
        foreach ($have as $key => $row) {
            if (! isset($want[$key]) && $row->dismissed_at === null) {
                $row->delete();
            }
        }
    }

    /** 画面に渡す形。 */
    public static function row(StaffAllowance $a, array $projectNames = [], array $staffNames = []): array
    {
        return [
            'id' => $a->id,
            'staffId' => $a->staff_id,
            'staff' => $staffNames[$a->staff_id] ?? $a->staff_id,
            'projectId' => (string) ($a->project_id ?? ''),
            'project' => $a->project_id ? ($projectNames[$a->project_id] ?? $a->project_id) : '',
            'date' => $a->date->format('Y-m-d'),
            'md' => $a->date->format('n/j'),
            'kind' => $a->kind,
            'count' => $a->count,
            'unit' => self::unit($a),
            'amount' => self::unit($a) * $a->count,
            'note' => (string) ($a->note ?? ''),
            'by' => (string) ($a->created_by ?? ''),
            'auto' => $a->auto_key !== null,
            'switchable' => in_array($a->kind, self::SWITCHABLE, true),
        ];
    }
}
