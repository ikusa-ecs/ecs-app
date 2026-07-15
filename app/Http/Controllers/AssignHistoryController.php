<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * クライアント別アサイン履歴（/assign-history）。
 *
 * ねらい：リピート（常連）のお客様に「前回と同じ顔ぶれ」を送る配慮を助ける。
 * クライアント（projects.client）ごとに、
 *   - そのお客様の案件によく入っている「常連スタッフ」（入った案件数の多い順）
 *   - そのお客様の過去案件（新しい順）と、その案件のアサイン済みメンバー（氏名＋役割）
 * を一覧で見せる。継続アサインをすぐ判断できるようにする土台。
 *
 * データ元：案件＝projects／アサイン＝assignments（キャンセルは集計から除外）。
 * v1は「見るだけ」。この画面から直接のアサイン変更はしない。
 */
class AssignHistoryController extends Controller
{
    /** 曜日（日本語）。0=日 〜 6=土。 */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** クライアント名が空のときにまとめる見出し。 */
    private const NO_CLIENT_LABEL = '（クライアント未設定）';

    public function index(Request $request)
    {
        // アサイン（キャンセルは数えない）をまとめて引く。
        $assignments = Assignment::where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id', 'role', 'status']);

        // アサインに登場する案件・人を、一括で引いておく（N+1を避ける）。
        $projectIds = $assignments->pluck('project_id')->unique()->filter()->all();
        $projects = empty($projectIds)
            ? collect()
            : Project::whereIn('id', $projectIds)->get()->keyBy('id');

        // スタッフ名は people から id=>name で一括取得してマップする。
        $people = Person::whereIn('id', $assignments->pluck('staff_id')->unique()->filter()->all())
            ->get(['id', 'name'])
            ->pluck('name', 'id');

        // コンテンツID → 名前（案件見出し用）。
        $contentNames = Content::pluck('content_name', 'id');

        // 役割コードの並び順（AssignmentRole の定義順）。未知は末尾。
        $roleOrder = array_flip(array_keys(AssignmentRole::LABELS));

        // 案件ごとに「メンバー行」を組み立てる（氏名＋役割ラベル）。
        $membersByProject = $assignments->groupBy('project_id');

        // 案件ID → クライアント名（空は '' に揃える）。
        // アサインのある案件だけを対象にする（＝アサイン履歴なので）。
        $clientOf = [];
        foreach ($projectIds as $pid) {
            $p = $projects->get($pid);
            $clientOf[$pid] = $p ? trim((string) $p->client) : '';
        }

        // クライアント（client 文字列）ごとに案件をまとめる。
        $byClient = collect($projectIds)
            ->filter(fn ($pid) => $projects->has($pid))
            ->groupBy(fn ($pid) => $clientOf[$pid]);

        // ?client=名前 が来たら、そのクライアントだけに絞る。
        // 「（クライアント未設定）」が来たら空クライアントだけ。
        $filter = trim((string) $request->query('client', ''));
        if ($filter !== '') {
            $key = ($filter === self::NO_CLIENT_LABEL) ? '' : $filter;
            $byClient = $byClient->filter(fn ($ids, $client) => (string) $client === $key);
        }

        // クライアント名の一覧（絞り込みプルダウン用）。空クライアントがあれば末尾に置く。
        $clientNames = $byClient->keys()
            ->sortBy(fn ($c) => $c === '' ? "\u{ffff}" : $c, SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn ($c) => [
                'value' => $c === '' ? self::NO_CLIENT_LABEL : $c,
                'label' => $c === '' ? self::NO_CLIENT_LABEL : $c,
            ]);

        // 表示用のクライアント別データを作る。
        $clients = $byClient
            ->map(function ($ids, $client) use (
                $projects, $membersByProject, $people, $contentNames, $roleOrder
            ) {
                // このクライアントの案件（重複IDを除く）。
                $ids = collect($ids)->unique()->values();

                // 常連スタッフ：このクライアントの「何案件」に入ったか（案件単位で数える）。
                $countByStaff = [];
                foreach ($ids as $pid) {
                    $seen = [];
                    foreach ($membersByProject->get($pid, collect()) as $a) {
                        $sid = $a->staff_id;
                        if ($sid === null || $sid === '' || isset($seen[$sid])) {
                            continue;
                        }
                        $seen[$sid] = true;
                        $countByStaff[$sid] = ($countByStaff[$sid] ?? 0) + 1;
                    }
                }
                arsort($countByStaff);
                $regulars = [];
                foreach ($countByStaff as $sid => $cnt) {
                    $regulars[] = [
                        'name'  => $people[$sid] ?? $sid,
                        'count' => $cnt,
                    ];
                }

                // 案件一覧（start_date の新しい順）。
                $projectRows = $ids
                    ->map(fn ($pid) => $projects->get($pid))
                    ->filter()
                    ->sortByDesc(fn (Project $p) => $p->start_date ? $p->start_date->timestamp : 0)
                    ->values()
                    ->map(function (Project $p) use ($membersByProject, $people, $contentNames, $roleOrder) {
                        // 見出しコンテンツ名（複数あれば「／」でつなぐ）。無ければ案件名。
                        $cids = is_array($p->content_ids) ? $p->content_ids : [];
                        $contentLabels = collect($cids)
                            ->map(fn ($id) => $contentNames[$id] ?? null)
                            ->filter()
                            ->values();
                        $title = $contentLabels->isNotEmpty()
                            ? $contentLabels->implode('／')
                            : ($p->project_name ?: $p->id);

                        // アサイン済みメンバー（氏名＋役割ラベル）。役割順で並べる。
                        $members = $membersByProject->get($p->id, collect())
                            ->map(fn ($a) => [
                                'name'      => $people[$a->staff_id] ?? $a->staff_id,
                                'role'      => $a->role ?: '',
                                'roleLabel' => AssignmentRole::label($a->role),
                            ])
                            ->sortBy(fn ($m) => $roleOrder[$m['role']] ?? 99)
                            ->values()
                            ->all();

                        return [
                            'title'   => $title,
                            'date'    => $this->fmtDate($p->start_date),
                            'members' => $members,
                        ];
                    })
                    ->all();

                return [
                    'label'    => $client === '' ? self::NO_CLIENT_LABEL : $client,
                    'regulars' => $regulars,
                    'projects' => $projectRows,
                ];
            })
            // 案件数の多いクライアントを上に。空クライアントは末尾へ。
            ->sortBy(fn ($c) => $c['label'] === self::NO_CLIENT_LABEL ? 1 : 0)
            ->sortByDesc(fn ($c) => count($c['projects']))
            ->values();

        return view('assign_history', [
            'clients'      => $clients,
            'clientNames'  => $clientNames,
            'selectedClient' => $filter,
        ]);
    }

    /**
     * クライアント履歴の照会（AJAX用・GET /clients/lookup?client=名前）。
     *
     * 案件登録フォームなどから呼び、そのお客様が「リピート（常連）」かどうかと、
     * 過去案件（start_date の新しい順・最大5件）の日付・担当ディレクターを JSON で返す。
     * 判定は projects.client の完全一致（前後の空白は落とす）。1件でもあれば常連。
     * 新規登録中の案件はまだ保存されていない前提なので、既存に同名クライアントがあれば常連とみなす。
     *
     * @return \Illuminate\Http\JsonResponse
     *   {"isRepeat": bool, "count": 過去案件数, "entries":[{"date","director","project_name"}]}
     */
    public function lookup(Request $request)
    {
        $client = trim((string) $request->query('client', ''));

        if ($client === '') {
            return response()->json(['isRepeat' => false, 'count' => 0, 'entries' => []]);
        }

        // このお客様の案件を、担当ディレクター（people）付きで新しい順に一括取得（N+1回避）。
        $projects = Project::with('director:id,name')
            ->whereNotNull('client')
            ->where('client', $client)
            ->orderByDesc('start_date')
            ->get();

        $count = $projects->count();

        // 表示用に最大5件だけ、日付・担当D・案件名を取り出す。
        $entries = $projects->take(5)
            ->map(fn (Project $p) => [
                'date'         => $p->start_date
                    ? $p->start_date->format('Y') . '/' . (int) $p->start_date->format('n') . '/' . (int) $p->start_date->format('j')
                    : '',
                'director'     => $p->director->name ?? '未定',
                'project_name' => $p->project_name ?? '',
            ])
            ->values()
            ->all();

        return response()->json([
            'isRepeat' => $count > 0,
            'count'    => $count,
            'entries'  => $entries,
        ]);
    }

    /** 開催日を「2026/7/10（金）」の形にする。null は空。 */
    private function fmtDate(?Carbon $d): string
    {
        if (! $d) {
            return '';
        }

        return $d->format('Y') . '/' . (int) $d->format('n') . '/' . (int) $d->format('j')
            . '（' . self::WEEKDAYS[(int) $d->format('w')] . '）';
    }
}
