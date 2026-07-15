<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 自動アサインの「頭脳」＝候補スタッフ1人ぶんの“おすすめ度（点数）”と理由・警告を出す部品。
 *
 * 設計書11章のアサイン優先順位に沿う：
 *   ① 希望数・稼働状況 ＞ ② コンテンツ・メンバーとの相性 ＞ ③ 育成バランス ＞ ④ リスク回避
 *
 * 大事な前提（設計書11章の冒頭）：**提案までを自動化し、最終確定は人が行う**。
 * このクラスはDBを一切書き換えない。点数を返すだけ＝並べ替え・自動仮置きの材料。
 *
 * NG系（この日はNG／NGペア同席）は blocked=true にして「自動おすすめから外す」。
 * それ以外（同日ダブり・専属20件上限・寝坊×朝）は減点＋警告にとどめ、人が最終判断できるようにする
 * （既存アサイン画面の「ハード制約にせず警告で見せる」方針に合わせる）。
 *
 * 依存データ（重複クエリを避けるため、画面コントローラがすでに読んだものを受け取る）：
 *   - $wish       … staff_id => 'NG'|'希望'|'稼働可'|'未定'（この案件の日の希望。shift_preferences）
 *   - $sameDay    … staff_id => [同日・他案件の名前, ...]（ダブルブッキング検知）
 *   - $monthCount … staff_id => 今月のアサイン件数（月20件上限の見える化）
 *   - $repeatStaffIds … このクライアントの過去案件に参加した staff_id の集合（リピート継続の加点）
 *   - $projectContentNames … この案件のコンテンツ名（経験一致の判定用）
 */
class AssignmentScorer
{
    /** @var array<string,string> staff_id => availability */
    private array $wish;

    /** @var array<string,array<int,string>> staff_id => 同日・他案件名 */
    private array $sameDay;

    /** @var array<string,int> staff_id => 今月件数 */
    private array $monthCount;

    /** @var array<int,string> このクライアントの過去案件に出た staff_id */
    private array $repeatStaffIds;

    /** @var array<int,string> この案件のコンテンツ名 */
    private array $projectContentNames;

    /** @var array<int,string> この案件にすでに入っているスタッフの氏名一覧（NGペア判定用） */
    private array $projectMemberNames = [];

    /** この案件が「朝案件」か（集合が早い）。寝坊傾向の減点に使う。 */
    private bool $isMorning;

    public function __construct(
        private Project $project,
        private ?Carbon $date,
        array $wish = [],
        array $sameDay = [],
        array $monthCount = [],
        array $repeatStaffIds = [],
        array $projectContentNames = [],
        private int $monthCap = 20,
    ) {
        $this->wish = $wish;
        $this->sameDay = $sameDay;
        $this->monthCount = $monthCount;
        $this->repeatStaffIds = array_values($repeatStaffIds);
        $this->projectContentNames = array_values(array_filter($projectContentNames));
        $this->isMorning = $this->detectMorning($project);
    }

    /**
     * スタッフ1人を評価して返す。
     *
     * @return array{score:float, reasons:array<int,string>, warnings:array<int,string>, blocked:bool, blockReason:?string}
     */
    public function evaluate(Person $p): array
    {
        $id = $p->id;
        $reasons = [];
        $warnings = [];
        $score = 0.0;
        $blocked = false;
        $blockReason = null;

        $avail = $this->wish[$id] ?? null;
        $month = (int) ($this->monthCount[$id] ?? 0);

        // ── ハード（自動おすすめから外す）──────────────────────────
        // ① この日は本人がNG
        if ($avail === 'NG') {
            $blocked = true;
            $blockReason = 'この日は本人がNG';
        }
        // ② NGペアがこの案件にすでに入っている（同席させない）
        $ngHit = $this->ngConflict($p);
        if ($ngHit !== null) {
            $blocked = true;
            $blockReason = $blockReason ?? "NGペア（{$ngHit}）が同じ案件にいる";
        }

        // ── 第1優先：希望数・稼働状況（最大配点）────────────────────
        if ($avail === '希望') {
            $score += 35;
            $reasons[] = '本人が希望';
        } elseif ($avail === '稼働可') {
            $score += 20;
            $reasons[] = '稼働可';
        }
        // 今月まだ0件＝0件回避で強く優先
        if ($month === 0) {
            $score += 30;
            $reasons[] = '今月まだアサイン0件';
        }
        // 自社専属はできるだけ稼働（ただし月上限で頭打ち）
        if ($p->is_exclusive) {
            if ($month >= $this->monthCap) {
                $warnings[] = "自社専属だが今月{$this->monthCap}件上限に到達";
            } else {
                $score += 15;
                $reasons[] = '自社専属';
            }
        }

        // ── 第2優先：コンテンツ・メンバーとの相性 ───────────────────
        // リピート案件で、このクライアントの過去案件にも出ていた＝継続性
        if ($this->project->is_repeat && in_array($id, $this->repeatStaffIds, true)) {
            $score += 20;
            $reasons[] = 'リピート案件で前回も参加';
        }
        // この案件のコンテンツを経験している
        if ($this->hasContentExperience($p)) {
            $score += 15;
            $reasons[] = 'このコンテンツ経験あり';
        }

        // ── 第3優先：育成バランス（安定重視現場はベテランを厚く）──────
        if ($this->project->site_category === '安定重視' && $p->skill_level === 'ベテラン') {
            $score += 8;
            $reasons[] = 'ベテラン（安定重視の現場）';
        }

        // ── ブースト（積極的にアサインしたい人・設計書11章D）─────────
        if ($p->improves_atmosphere) {
            $score += 8;
            $reasons[] = '場の空気を良くする';
        }
        if ($p->can_follow_newbie) {
            $score += 8;
            $reasons[] = '新人フォローができる';
        }
        if ($p->self_starter) {
            $score += 8;
            $reasons[] = '自分で考えて動ける';
        }

        // ── 第4優先：リスク回避（減点＋警告。除外はしない）──────────
        if ($p->oversleeper && $this->isMorning) {
            $score -= 15;
            $warnings[] = '寝坊傾向×朝案件';
        }
        if (! empty($this->sameDay[$id])) {
            $score -= 40;
            $names = implode('・', $this->sameDay[$id]);
            $warnings[] = "同じ日に別案件あり（{$names}）";
        }

        return [
            'score' => round($score, 2),
            'reasons' => $reasons,
            'warnings' => $warnings,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
        ];
    }

    /**
     * この案件にすでに入っているスタッフの中に、この人のNG相手がいれば その名前 を返す。
     * ＄project->assignments を都度引かないよう、判定に必要な「同案件の氏名一覧」は
     * setProjectMemberNames() で外から渡せる。渡されていなければ判定しない（null）。
     */
    private function ngConflict(Person $p): ?string
    {
        if (empty($this->projectMemberNames)) {
            return null;
        }
        $ngNames = $p->relationLoaded('ngRelations')
            ? $p->ngRelations->pluck('partner_name')->all()
            : [];
        foreach ($ngNames as $n) {
            if ($n !== '' && in_array($n, $this->projectMemberNames, true)) {
                return $n;
            }
        }
        return null;
    }

    /** この案件にすでに入っているスタッフの氏名一覧を渡す（NGペア同席の判定に使う）。 */
    public function setProjectMemberNames(array $names): static
    {
        $this->projectMemberNames = array_values(array_filter($names));
        return $this;
    }

    /** この人が案件コンテンツを経験しているか（experienced_contents はコンテンツ名の配列）。 */
    private function hasContentExperience(Person $p): bool
    {
        if (empty($this->projectContentNames)) {
            return false;
        }
        $exp = is_array($p->experienced_contents) ? $p->experienced_contents : [];
        if (empty($exp)) {
            return false;
        }
        return count(array_intersect($this->projectContentNames, $exp)) > 0;
    }

    /** 集合時間が早い（朝9時より前）なら「朝案件」とみなす。時間未設定なら朝ではない。 */
    private function detectMorning(Project $project): bool
    {
        $t = $project->start_time ?: $project->staff_meeting_time;
        if (! $t || ! preg_match('/^(\d{1,2}):(\d{2})/', (string) $t, $m)) {
            return false;
        }
        return ((int) $m[1]) < 9;
    }
}
