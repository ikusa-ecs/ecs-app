<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * 危険日（高負荷日）の判定の正本（サーバー側・2026-09-16）。
 *
 * 【なぜ作ったか】
 * 危険日の判定は、これまで**画面のJS**（`public/ecs/data/cases.js` の `ECS_dangerCheck`）
 * にしか無かった。ダッシュボードのカレンダーを描くだけなら足りていたが、
 * 「危険日をイベプラのカレンダーに入れる」（2026-09-16 baba要望）には
 * **サーバーが危険日を知っている**必要がある（GASが読みに来るため）。
 *
 * ⚠ **数字は cases.js と1つでも違ってはいけない。**
 *   画面が赤くしている日と、カレンダーに入る日が食い違うと、誰も信用しなくなる。
 *   見張り＝`DangerDayRuleTest`（cases.js の中身を読んで、下の数字と同じか確かめる）。
 *   ⚠ ルールを変えるときは **cases.js とここの両方**を直す（テストが落ちて気づけるようにしてある）。
 *
 * 【危険日の決まり】次のどれかに当てはまる日
 *   ① 大型案件が同じ日に2件以上
 *   ② リアル系案件（オンライン以外）が同じ日に5件以上
 *   ③ その日の「必要スタッフ数（運営人数−1）」の合計が、スタッフ数の7割以上
 */
final class DangerDayRule
{
    /** ① 大型案件が同じ日に何件以上で危険か。 */
    public const BIG_MIN = 2;

    /** ② リアル系案件が同じ日に何件以上で危険か。 */
    public const REAL_MIN = 5;

    /** ③ スタッフ数の何割以上で危険か。 */
    public const STAFF_RATIO = 0.7;

    /**
     * 想定しているスタッフ数（アクティブ＋準アクティブ）。
     * ⚠ cases.js の `window.ECS_ACTIVE_STAFF` と同じ数にしておくこと。
     *   （「本番はDBで自動算出」と書いてあるが、2026-09-16 時点ではまだ暫定の目安のまま。
     *     自動算出に変えるなら、画面とサーバーの**両方**を同時に変える）
     */
    public const ACTIVE_STAFF = 40;

    /** 判定に使う「その日の案件」1件ぶん＝['scale'=>'大型','fmt'=>'real','need'=>8,'name'=>'…']。 */
    public static function check(array $items): array
    {
        $big = 0;
        $real = 0;
        $needSum = 0;

        foreach ($items as $it) {
            if (($it['scale'] ?? '') === '大型') {
                $big++;
            }
            if (in_array($it['fmt'] ?? '', ['real', 'long'], true)) {
                $real++;
            }
            $n = (int) ($it['need'] ?? 0);
            if ($n > 0) {
                $needSum += $n - 1;   // 必要スタッフ数＝運営人数−1（案件に必ず1名は社員が入る）
            }
        }

        $threshold = (int) ceil(self::ACTIVE_STAFF * self::STAFF_RATIO);
        $reasons = [];

        if ($big >= self::BIG_MIN) {
            $reasons[] = '大型案件が'.$big.'件重なっています';
        }
        if ($real >= self::REAL_MIN) {
            $reasons[] = 'リアル系案件が'.$real.'件重なっています';
        }
        if ($needSum >= $threshold) {
            $reasons[] = '必要スタッフ数の合計が'.$needSum.'名（スタッフ'
                .self::ACTIVE_STAFF.'名の7割＝'.$threshold.'名以上）';
        }

        return [
            'danger' => $reasons !== [],
            'reasons' => $reasons,
            'count' => count($items),
            'big' => $big,
            'real' => $real,
            'needSum' => $needSum,
            'staff' => self::ACTIVE_STAFF,
            'threshold' => $threshold,
        ];
    }

    /**
     * 期間内の危険日を返す（自動判定 ＋ 手で足した危険日）。
     *
     * 返り値＝日付の昇順で
     *   ['date'=>'2026-10-03','kind'=>'自動|手動|自動と手動','reasons'=>[...],'projects'=>['案件名',...]]
     *
     * ⚠ 下書き・完了・キャンセルの案件は数えない（画面のカレンダーと同じ）。
     * ⚠ 手で足した危険日は、案件が1件も無くても危険日として返す（人が「この日は危険」と決めた日だから）。
     *
     * @param  string|null  $office  null＝拠点で絞らない
     */
    public static function between(Carbon $from, Carbon $to, ?string $office = null): array
    {
        $projects = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true));

        // 日付ごとに、判定に使う形へ詰め替える。
        $byDate = [];
        foreach ($projects as $p) {
            $date = $p->start_date->format('Y-m-d');
            $byDate[$date][] = [
                'scale' => (string) ($p->scale ?? ''),
                'fmt' => ProjectFormats::countCode($p->format),
                // ⚠ 「6〜8名」のような範囲は**少ないほう**で数える。
                //   画面のJSが parseInt('6〜8') ＝ 6 と読むので、それにそろえる
                //   （ここだけ多いほうで数えると、画面より危険日が増えて食い違う）。
                'need' => (int) ($p->required_count_min ?: $p->required_count),
                'name' => (string) $p->project_name,
            ];
        }

        $out = [];
        foreach ($byDate as $date => $items) {
            $res = self::check($items);
            if ($res['danger']) {
                $out[$date] = [
                    'date' => $date,
                    'kind' => '自動',
                    'reasons' => $res['reasons'],
                    'projects' => array_column($items, 'name'),
                ];
            }
        }

        // 手で足した危険日（共通設定）。期間内のものだけ。
        foreach (DangerDays::dates($office) as $date) {
            if ($date < $from->format('Y-m-d') || $date > $to->format('Y-m-d')) {
                continue;
            }
            if (isset($out[$date])) {
                $out[$date]['kind'] = '自動と手動';
                continue;
            }
            $out[$date] = [
                'date' => $date,
                'kind' => '手動',
                'reasons' => ['共通設定で「危険日」に指定されています'],
                'projects' => array_column($byDate[$date] ?? [], 'name'),
            ];
        }

        ksort($out);

        return array_values($out);
    }
}
