<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * D決め（S-017 / /assign-director）。月次MTGで各案件の
 * D（ディレクター）／SD（サブディレクター）をカレンダー上で決める画面。
 *
 * 作りの型（このアプリ共通）：
 *   Controller が view([...]) にデータを渡す → Blade が window.ECS_* = @json(...) でJSへ橋渡し
 *   → DBが空ならBlade側で既存の見本（cases.js）にフォールバックする。
 *
 * Dの保存先：assignments テーブルを role='D' / role='SD' で使う。
 *   （案件 × スタッフ × 日 が1行。D・SDは別の人なので unique 制約に収まる）
 *   ※ 既存の director_id（projects）はアサイン以外の用途で使われているため、
 *     ここでは触らず、D/SD の決定は assignments に集約する。
 */
class AssignDirectorController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // マスに並べる社員＝全社員（people の role='employee'）。
        // 既定はイベプラ＋新人だけ表示、画面の「＋全社員」で全員に広げる（front 側で出し分け）。
        // 同姓の取り違えを防ぐため、保存・判定はすべて社員ID基準で行う。
        $employees = Person::employees()
            ->orderBy('id')
            ->get()
            ->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'surname' => $this->surname($p->name),       // 例: '田中 健一' → '田中'
                'department' => $p->department,               // イベプラ / セールス / クリエイティブ
                'planner' => $p->department === 'イベプラ',   // 既定表示の対象
                'newbie' => $p->skill_level === '新人',       // 在籍1年未満＝既定表示の対象
            ])
            ->values();

        // この案件のD/SDを assignments から引く（role='D' / 'SD'）。
        // project_id => ['D'=>['id'=>staff_id,'status'=>...], 'SD'=>...]
        $dirByProject = Assignment::whereIn('role', ['D', 'SD'])
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role', 'status'])
            ->groupBy('project_id')
            ->map(function ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[$r->role] = ['id' => $r->staff_id, 'status' => $r->status];
                }
                return $out;
            });

        // FC（フロアコーディネーター）もこの画面で選べるようにする。
        // project_id => [staff_id, ...]（role='FC'・キャンセル除く・重複排除）。
        $fcByProject = Assignment::where('role', 'FC')
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->values()->all());

        // コンテンツID → コンテンツ名（見出し用）
        $contentNames = Content::pluck('content_name', 'id');

        // 過去アーカイブ・下書きは除外（status='完了'＝アーカイブ相当 / '下書き'）。
        $cases = Project::orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => ! in_array($p->status, ['完了', '下書き'], true))
            ->map(function (Project $p) use ($today, $dirByProject, $fcByProject, $contentNames) {
                $start = $p->start_date;
                $off = $start ? (int) $today->diffInDays($start, false) : 0;

                $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
                $content = $firstContentId
                    ? ($contentNames[$firstContentId] ?? $p->project_name)
                    : $p->project_name;

                $format = (string) ($p->format ?? '');
                $fmt = '';
                if (mb_strpos($format, 'オンライン') !== false) {
                    $fmt = 'online';
                } elseif (mb_strpos($format, 'ロング') !== false) {
                    $fmt = 'long';
                } elseif (mb_strpos($format, 'リアル') !== false) {
                    $fmt = 'real';
                }

                // 決まっているD/SD（社員ID。未決は null）
                $decided = $dirByProject->get($p->id, []);

                return [
                    'id' => $p->id,
                    'off' => $off,
                    'name' => $p->project_name,
                    'client' => $p->client ?? '',
                    'content' => $content,
                    'scale' => $p->scale ?? '中型',
                    'format' => $format,
                    'fmt' => $fmt,
                    'dirId' => $decided['D']['id'] ?? null,    // D の社員ID
                    'sdId' => $decided['SD']['id'] ?? null,    // SD の社員ID
                    'fcIds' => $fcByProject->get($p->id, []),  // FC の社員ID（複数可）
                    'dStatus' => $decided['D']['status'] ?? null,
                    'sStatus' => $decided['SD']['status'] ?? null,
                    'dayType' => $p->date_type ?? '本番',
                    'status' => $p->status ?? '',
                    'guests' => $p->guest_count,
                    'teams' => $p->team_count,
                    'repeat' => (bool) $p->is_repeat,
                    'evStart' => $p->event_start_time,
                    'evEnd' => $p->event_end_time,
                    'meet' => $p->start_time,
                    'leave' => $p->end_time,
                    'date' => optional($start)->format('Y-m-d'),  // 保存に使う実日付
                ];
            })
            ->values();

        // D/SD/FC 以外のロール（OP・MC等）でその日アサイン済みの社員（チップの色分け用）。
        // FCはこの画面で選べるので $fcByProject 側で扱い、ここでは二重に数えないよう除外する。
        // 形：日付(Y-m-d) => [ staff_id => [role, ...] ]
        $empBusy = [];
        Assignment::whereNotIn('role', ['D', 'SD', 'FC'])
            ->where('status', '!=', 'キャンセル')
            ->get(['staff_id', 'date', 'role'])
            ->each(function ($a) use (&$empBusy) {
                $key = Carbon::parse($a->date)->format('Y-m-d');
                $empBusy[$key][$a->staff_id][] = $a->role;
            });

        return view('assign_director', [
            'cases' => $cases,
            'employees' => $employees,
            'empBusy' => $empBusy,
        ]);
    }

    /**
     * D/SD の決定を保存する。
     * フォームは案件ごとに「D社員ID」「SD社員ID」を送る想定。
     * assignments を role='D' / 'SD' で、その案件×その人×開催日に1行ずつ保存する。
     * 外された（未定/なしに戻された）D・SDは削除する。
     *
     * 受け取る形：
     *   dir[<project_id>]  = <社員ID or 空>
     *   sd[<project_id>]   = <社員ID or 空>
     *   status             = '仮' or '確定'（既定: 仮）
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'dir' => ['nullable', 'array'],
            'dir.*' => ['nullable', 'string'],
            'sd' => ['nullable', 'array'],
            'sd.*' => ['nullable', 'string'],
            'fc' => ['nullable', 'array'],
            'fc.*' => ['nullable', 'array'],
            'fc.*.*' => ['nullable', 'string'],
            'status' => ['nullable', 'in:仮,確定'],
        ]);

        $status = $data['status'] ?? '仮';
        $dirs = $data['dir'] ?? [];
        $sds = $data['sd'] ?? [];
        $fcs = $data['fc'] ?? [];   // [案件ID => [社員ID, ...]]（FCは複数可）
        $now = Carbon::now();

        // 対象になる案件IDを集める（D・SD・FC のいずれかが送られてきた案件）
        $projectIds = collect(array_keys($dirs))
            ->merge(array_keys($sds))
            ->merge(array_keys($fcs))
            ->unique()
            ->values();

        // 開催日を引く（assignments は日付必須）。日付の無い案件はスキップ。
        $startDates = Project::whereIn('id', $projectIds)
            ->pluck('start_date', 'id');

        $saved = 0;
        $skipped = [];

        DB::transaction(function () use ($projectIds, $dirs, $sds, $fcs, $startDates, $status, $now, &$saved, &$skipped) {
            foreach ($projectIds as $pid) {
                $start = $startDates[$pid] ?? null;
                if (! $start) {
                    $skipped[] = $pid;   // 開催日未設定の案件はDを保存できない
                    continue;
                }
                $date = Carbon::parse($start)->format('Y-m-d');

                // いま選ばれているD/SDの社員ID（未定／なしは空）
                $dId = trim((string) ($dirs[$pid] ?? ''));
                $sId = trim((string) ($sds[$pid] ?? ''));
                $keep = array_values(array_filter([$dId, $sId], fn ($x) => $x !== ''));

                // 以前のD/SDで「今回は選ばれていない人」の行だけ消す（担当を外す操作を反映）。
                // ※ 日付は whereDate で「日付部分」だけ照合する（DB保存は 00:00:00 付きのため、
                //   文字列 'Y-m-d' の完全一致だと取りこぼして重複エラーになる）。
                Assignment::where('project_id', $pid)
                    ->whereDate('date', $date)
                    ->whereIn('role', ['D', 'SD'])
                    ->when($keep, fn ($q) => $q->whereNotIn('staff_id', $keep))
                    ->delete();

                // 選ばれたD/SDを保存。同じ案件×人×日が既にあれば role/status を更新するだけ。
                // ※ 既存行を探すときも whereDate で「日付部分」だけ照合する（DB保存は 00:00:00 付き。
                //   updateOrCreate は date を完全一致で探すため取りこぼし→新規作成→重複エラーになる）。
                foreach (['D' => $dId, 'SD' => $sId] as $role => $staffId) {
                    if ($staffId === '') {
                        continue;   // 未定／なし＝行を作らない
                    }
                    $existing = Assignment::where('project_id', $pid)
                        ->where('staff_id', $staffId)
                        ->whereDate('date', $date)
                        ->first();
                    if ($existing) {
                        $existing->update(['role' => $role, 'status' => $status, 'assigned_at' => $now]);
                    } else {
                        Assignment::create([
                            'project_id' => $pid,
                            'staff_id' => $staffId,
                            'date' => $date,
                            'role' => $role,
                            'status' => $status,
                            'assigned_by' => null,    // 認証導入後に操作者を入れる
                            'assigned_at' => $now,
                        ]);
                    }
                    $saved++;
                }

                // FC（複数可）を同期：いま選ばれているFC集合に揃える。
                // 集合に無い既存FCは削除し、有るものは作成／更新する。
                $fcIds = array_values(array_filter(
                    array_map(fn ($x) => trim((string) $x), (array) ($fcs[$pid] ?? [])),
                    fn ($x) => $x !== ''
                ));
                Assignment::where('project_id', $pid)
                    ->whereDate('date', $date)
                    ->where('role', 'FC')
                    ->when($fcIds, fn ($q) => $q->whereNotIn('staff_id', $fcIds))
                    ->delete();
                foreach ($fcIds as $sid) {
                    // 同じ人が同案件でD/SDに就いている場合は、その役割を優先（FCは付けない）。
                    if ($sid === $dId || $sid === $sId) {
                        continue;
                    }
                    $existing = Assignment::where('project_id', $pid)
                        ->where('staff_id', $sid)
                        ->whereDate('date', $date)
                        ->first();
                    if ($existing) {
                        $existing->update(['role' => 'FC', 'status' => $status, 'assigned_at' => $now]);
                    } else {
                        Assignment::create([
                            'project_id' => $pid,
                            'staff_id' => $sid,
                            'date' => $date,
                            'role' => 'FC',
                            'status' => $status,
                            'assigned_by' => null,
                            'assigned_at' => $now,
                        ]);
                    }
                    $saved++;
                }
            }
        });

        $msg = "D／SD／FCの担当を {$saved} 件、「{$status}」で保存しました。";
        if (! empty($skipped)) {
            $msg .= '（開催日が未設定の案件 ' . count($skipped) . ' 件は保存できませんでした）';
        }

        return redirect('/assign-director')->with('status', $msg);
    }

    /** 氏名から姓だけを取り出す（'田中 健一' → '田中'。全角/半角スペース対応）。 */
    private function surname(string $name): string
    {
        $parts = preg_split('/[\s\x{3000}]+/u', trim($name));

        return $parts[0] ?? $name;
    }
}
