<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectHistory;
use Illuminate\Support\Carbon;

/**
 * スタッフ画面の「最近の変更（あなたに関係するもの）」の正本（2026-09-09 baba要望）。
 *
 * 【babaの言葉】「スタッフの画面に追加項目で、時間が編集されたものや案件が追加された みたいな、
 *   社員側の編集履歴みたいなやつの、スタッフにかかわることだけがのっているバージョンがほしい」
 *
 * 【何を出すか】社員側の編集履歴（project_histories）から、**スタッフに関係するものだけ**を選ぶ。
 *   ① 自分がアサインされている案件（仮・確定）の変更
 *   ② 自分がエントリーした案件の変更
 *   ③ 公開中（募集中）の案件が**新しく出た**こと
 *
 * ⚠ **見せてよい案件だけ**にする。社員だけが知っている案件の変更を、
 *   関係のないスタッフに見せない（自分が関わる案件か、公開中の募集案件だけ）。
 * ⚠ **スタッフに関係する項目だけ**にする（下の FIELDS）。
 *   運営人数・確度・売上のような社内の数字を、スタッフの画面に出さない。
 * ⚠ 過去の案件の変更は出さない（もう関係ないため）。開催日が今日以降のものだけ。
 */
final class StaffProjectNews
{
    /** 何日前までさかのぼるか（画面が長くなりすぎないように）。 */
    public const DAYS = 30;

    /** 最大何件出すか。 */
    public const LIMIT = 30;

    /**
     * スタッフに関係する項目（DBの列名）。ここに無い項目の変更は出さない。
     *
     * ⚠ 日本語名は App\Support\ProjectFieldLabels が正本（ここに書き写さない）。
     * ⚠ 増やすときは「スタッフが知って意味があるか」で考える。
     *   運営人数・お客様人数・確度・拠点などは**社内の都合**なので入れない。
     */
    public const FIELDS = [
        'start_date',          // 開催日
        'start_time',          // 集合時間（スタッフ）
        'end_time',            // 解散時間（スタッフ）
        'assembly_type',       // 集合形式
        'assembly_detail',     // 集合場所の詳細
        'location',            // 会場住所
        'project_name',        // 案件名
        'format',              // 実施形態
        'online_tool',         // オンラインツール
        'transport',           // 移動・車両
        'lodging',             // 宿泊
        'catering',            // ケータリング
    ];

    /**
     * その人に関係する「最近の変更」。新しい順。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forPerson(?Person $person): array
    {
        if (! $person) {
            return [];
        }

        $today = Carbon::today();
        $since = $today->copy()->subDays(self::DAYS);

        // ① 自分が関わる案件（アサイン＋エントリー）。キャンセルは除く。
        $mine = Assignment::where('staff_id', $person->id)
            ->where('status', '!=', 'キャンセル')
            ->pluck('project_id')
            ->merge(Application::where('staff_id', $person->id)->pluck('project_id'))
            ->unique();

        // ② 公開中の案件（募集としてスタッフに見えているもの）。
        //    ⚠ 公開していない案件は、そもそもスタッフに見せていないので出さない。
        $open = Project::where('staff_published', true)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today->format('Y-m-d'))
            ->notCancelled()
            ->pluck('id');

        $ids = $mine->merge($open)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        // 開催日が今日以降の案件だけ（終わった案件の変更は出さない）。
        $projects = Project::whereIn('id', $ids)
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today->format('Y-m-d'))
            ->notCancelled()
            ->get(['id', 'project_name', 'start_date', 'client', 'staff_published'])
            ->keyBy('id');

        if ($projects->isEmpty()) {
            return [];
        }

        $mineSet = $mine->flip();

        $rows = ProjectHistory::whereIn('project_id', $projects->keys())
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                // 変更は「スタッフに関係する項目」だけ。新しく出た案件（created）はそのまま。
                $q->where('action', 'created')
                    ->orWhere(fn ($qq) => $qq->where('action', 'updated')->whereIn('field', self::FIELDS));
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT * 3)   // 出せない行を落としたあとで LIMIT に切るので少し多めに引く
            ->get();

        $out = [];
        foreach ($rows as $h) {
            $p = $projects[$h->project_id] ?? null;
            if (! $p) {
                continue;
            }

            // ⚠ 「新しく出た案件」は、いま公開中のものだけ知らせる。
            //   下書きのまま登録された案件を「案件が追加されました」と出すと、
            //   スタッフには見えない案件の話になってしまう。
            if ($h->action === 'created' && ! $p->staff_published) {
                continue;
            }

            $out[] = [
                'id' => $h->id,
                'projectId' => $h->project_id,
                'name' => (string) ($p->project_name ?: $h->project_name),
                'client' => (string) ($p->client ?? ''),
                'date' => optional($p->start_date)->format('Y-m-d'),
                'action' => $h->action,
                // 日本語名の正本は ProjectFieldLabels。記録した当時の名前が残っていればそれを使う。
                'label' => (string) ($h->field_label ?: ProjectFieldLabels::label((string) $h->field) ?: ''),
                'from' => (string) ($h->old_value ?? ''),
                'to' => (string) ($h->new_value ?? ''),
                'at' => optional($h->created_at)->format('Y-m-d H:i'),
                // 自分が関わっている案件か（＝「あなたの案件」と印を付ける）。
                'mine' => isset($mineSet[$h->project_id]),
            ];

            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }
}
