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
 * 大型手当＝1回2,000円（マニュアルには無い・2026-10-13 baba）。
 */
final class StaffAllowances
{
    /**
     * 事前リハーサルで4時間以上拘束したとき＝イベント1日分の日給（基本報酬）で数える。
     * 規定（2026-10-13 babaが貼った「基本報酬・追加報酬（手当）」）＝「事前リハーサルにおいて4時間以上拘束が発生した場合は
     * イベント1日分の日給」。最初は6時間と聞いて作った→規定に合わせて4時間に直した。
     */
    public const SIX_HOURS = '1日分として数える（4時間以上のリハ）';

    /**
     * 種類 => 1回の金額（null＝金額を手で入れる／SIX_HOURS は案件の実施形態から決める）。
     * 正＝スタッフ向けの報酬規定「■追加報酬（手当）」（2026-10-13 baba）。
     */
    public const KINDS = [
        '運転手当' => 2000,          // 個人の移動以外でトータル20分以上運転
        'スライド手当' => 1000,      // オンラインのみ・1案件ごと
        'MC巡回手当' => 2000,        // オンラインのみ・1案件でMC＋巡回の両方（MC手当にプラス）
        '事前レクチャー手当' => 2000,
        'MC手当' => 1000,
        '大型MC手当' => 2000,        // 通常のMC手当・リーダー手当とは併用不可
        '英語手当' => 2000,
        'リーダー手当' => 2000,
        'ディレクター手当' => 5000,
        '大型手当' => 2000,
        '宿泊手当' => 2000,          // 1泊ごと・スタッフ都合の前泊は対象外
        'ロング手当' => 2000,        // 集合〜解散が9時間を超える（リアルのみ）
        '前日設営手当' => 4000,
        '事前リハーサル手当' => 2000,
        self::SIX_HOURS => null,
        '片付け手当' => 1000,        // オフィスまで移動して荷下ろし・荷揚げ・片付けの3つすべて
        '深夜設営手当' => 2250,      // 22:00以降に勤務開始・5時間以上
        'キャンセル料' => null,      // IKUSA側からのキャンセル。金額を入れる（1/3・1/2・全額＋交通費）
        self::LATE => null,          // 遅刻の減額。金額を入れる＝請求書の目安から**引く**
        'その他' => null,
    ];

    /** 遅刻の減額（金額を入れる・請求書の目安ではマイナス）。 */
    public const LATE = '遅刻の減額';

    /** 自動で入れた行で切り替えられる種類（リハ・設営）。 */
    public const SWITCHABLE = ['前日設営手当', '事前リハーサル手当', self::SIX_HOURS, 'ディレクター手当'];

    /** 種類ごとの説明（入力欄の横に出す）。 */
    public const HINTS = [
        '運転手当' => 'IKUSAの依頼で、個人の移動以外でトータル20分以上運転（2,000円）※事前に「請求対象」と明示されたときだけ',
        'スライド手当' => '【オンラインのみ】当日にスライドを担当（1案件1,000円）',
        'MC巡回手当' => '【オンラインのみ】1案件の中でMC＋巡回の両方を担当（2,000円・MC手当にプラス・信長グリーティングも含む）※「請求対象」と明示されたときだけ',
        '事前レクチャー手当' => '全体集合より前（別日も）にレクチャー（1回2,000円）※「請求対象」と明示されたときだけ・動画視聴は対象外',
        'MC手当' => '1案件1,000円。※アサインの役割がMCの日は自動で数えます（同じ日に入れても二重にはなりません）',
        '大型MC手当' => '【大型】で事前にスケジュールを確保していたMC（2,000円）※通常のMC手当・リーダー手当とは併用不可＝同じ日の自動のMC手当は外します',
        '英語手当' => '当日に英語での対応をメインに担当（1案件2,000円）',
        'リーダー手当' => '大型・高難易度で社員の代わりにスタッフへ指示を出す（1案件2,000円）※「請求対象」と明示されたときだけ',
        'ディレクター手当' => 'イベントのディレクター（1件5,000円）※「請求対象」と明示されたときだけ',
        '大型手当' => 'アサイン募集時に【大型】と明記された案件（2,000円）',
        '宿泊手当' => '1泊2,000円。※案件の宿泊が「前泊」の日は自動で数えます（後泊などアサインで分からない日に入れてください）。スタッフ都合の前泊は対象外',
        'ロング手当' => '集合〜解散が9時間を超える（2,000円・リアルのみ）。※実施形態がリアルロングの日は自動で数えます',
        '前日設営手当' => '前日に前乗りして設営（4,000円）。※日程種別が前日設営のアサインは、手当の記録に自動で入ります',
        '事前リハーサル手当' => '1回2,000円。※日程種別がリハ日のアサインは、手当の記録に自動で入ります',
        self::SIX_HOURS => '事前リハーサルで4時間以上拘束したとき＝イベント1日分の日給（リアル9,000円・オンライン6,000円。案件の実施形態で決まります）',
        '片付け手当' => 'イベント後にオフィスまで移動し、荷下ろし・荷揚げ・片付けの3つすべて（1,000円）',
        '深夜設営手当' => '22:00以降に勤務開始・5時間以上（2,250円）',
        'キャンセル料' => 'IKUSA側からのキャンセル。金額を入れてください：当日（集合1時間前を過ぎて）＝基本報酬全額＋交通費／当日（前日19時〜集合1時間前）＝1/2／前日（前々日19時〜前日19時）＝1/3。割り切れなければ1円繰り上げ。体調不良によるキャンセルは対象外',
        self::LATE => '減らす金額を入れてください（請求書の目安から引きます）。計算＝日当÷予定労働時間×遅刻時間（30分切り上げ）。例：9,000円÷5時間×2時間＝3,600円。集合10分前までに連絡があり10分以内の遅刻・事前に承諾済み・電車の遅延（遅延証明）や不慮の事故などは、管理者の判断で減額しないことがあります',
        'その他' => '金額とメモを入れてください',
    ];

    /** 金額を手で入れる種類（その他）。 */
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
            ->get(['project_id', 'staff_id', 'role', 'date']);
        $projects = Project::whereIn('id', $asg->pluck('project_id')->unique())
            ->get(['id', 'project_name', 'content_ids', 'content_names', 'date_type', 'is_cancelled', 'format'])->keyBy('id');
        $staff = \App\Models\Person::staff()->whereIn('id', $asg->pluck('staff_id')->unique())->pluck('id')->flip();
        $contentNames = \App\Models\Content::pluck('content_name', 'id')->all();

        $want = [];
        foreach ($asg as $a) {
            $p = $projects->get($a->project_id);
            if (! $p || $p->is_cancelled || ! isset($staff[$a->staff_id])) {
                continue;
            }
            // ⚠ ARENA場所貸しは「Dポジションの人だけD手当（ディレクター手当）・FCなどほかの役割は手当なし」
            //   （2026-10-13 baba「Dポジションは D手当が入るけど、FCだったらD手当無い」）。
            //   「ARENA場所貸し(ライブリハ)」は取込でリハ日と読まれていた＝事前リハーサル手当にもしない。
            if (RookieFcPlan::isArenaRental($p, $contentNames)) {
                $kind = $a->role === AssignmentRole::D ? 'ディレクター手当' : null;
            } else {
                $kind = match ((string) $p->date_type) {
                    '前日設営' => '前日設営手当',
                    'リハ日' => '事前リハーサル手当',
                    default => null,
                };
            }
            if ($kind === null) {
                continue;
            }
            $date = Carbon::parse($a->date)->format('Y-m-d');
            $want['auto:'.$a->project_id.':'.$a->staff_id.':'.$date] = [$a->staff_id, $a->project_id, $date, $kind];
        }

        // いま入っている自動の行（この範囲・この人／この案件）。手で種類を切り替えた行は鍵の頭が「auto*:」。
        $have = StaffAllowance::whereNotNull('auto_key')
            ->whereDate('date', '>=', $from->format('Y-m-d'))->whereDate('date', '<=', $to->format('Y-m-d'))
            ->when($staffIds !== null, fn ($q) => $q->whereIn('staff_id', $staffIds))
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->get()->keyBy(fn ($r) => self::baseKey((string) $r->auto_key));

        foreach ($want as $key => [$sid, $pid, $date, $kind]) {
            if ($have->has($key)) {
                // もう入っている（消した印があっても入れ直さない）。
                // ただし**誰も触っていない自動の行**で、決まりが変わって種類がずれたものは直す
                // （2026-10-13：場所貸しに事前リハーサル手当が入っていた）。手で種類を切り替えた行は触らない。
                $row = $have->get($key);
                if ($row->dismissed_at === null && $row->kind !== $kind && ! self::isTouched($row)) {
                    $row->kind = $kind;
                    $row->amount = null;
                    $row->save();
                }

                continue;
            }
            StaffAllowance::create(['staff_id' => $sid, 'project_id' => $pid, 'date' => $date, 'kind' => $kind,
                'count' => 1, 'auto_key' => $key, 'created_by' => '自動（アサイン）']);
        }
        // アサインが無くなった・決まりで要らなくなった自動の行は取り除く
        // （消した印のある行は印として残す／手で種類を切り替えた行は人の判断なので残す）。
        foreach ($have as $key => $row) {
            if (! isset($want[$key]) && $row->dismissed_at === null && ! self::isTouched($row)) {
                $row->delete();
            }
        }
    }

    /**
     * 自動で入れた行を「手で直した」印にする（種類を切り替えたとき）。
     * 印の付いた行は、決まりが変わっても自動で種類を直さない（人の判断を上書きしない）。
     */
    public static function markTouched(StaffAllowance $a): void
    {
        if ($a->auto_key !== null && str_starts_with($a->auto_key, 'auto:')) {
            $a->auto_key = 'auto*:'.substr($a->auto_key, 5);
        }
    }

    private static function isTouched(StaffAllowance $a): bool
    {
        return str_starts_with((string) $a->auto_key, 'auto*:');
    }

    /** 自動の鍵から「手で直した」印を外した形（同じアサインかどうかを比べる用）。 */
    private static function baseKey(string $key): string
    {
        return str_starts_with($key, 'auto*:') ? 'auto:'.substr($key, 6) : $key;
    }

    /** 画面に渡す形。 */
    public static function row(StaffAllowance $a, array $projectNames = [], array $staffNames = [], array $projectClients = []): array
    {
        return [
            // 企業様名（2026-10-13 baba「案件メモに企業様名も」）。一覧の「案件・メモ」の列に出す。品目名には入れない。
            'client' => $a->project_id ? (string) ($projectClients[$a->project_id] ?? '') : '',
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
