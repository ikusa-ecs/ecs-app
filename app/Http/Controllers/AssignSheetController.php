<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Models\Person;
use App\Models\Project;
use App\Support\AssignmentRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * アサイン表（/assign-sheet）。
 *
 * ねらい：これまで別々だった「案件一覧」と「スタッフアサイン」を1画面にまとめ、
 * 現場で使っている “東京アサイン表”（Excel雛型）と同じ「1案件＝縦カード」を横に並べて、
 * 案件情報と割り当てメンバー（名前＋ポジション）を一緒に見られるようにする。
 *
 * データ元：案件＝projects／メンバー＝assignments（既存の実データ）。
 * v1は「見るだけ」（月を選んで表示・並べ替え・絞り込み）。この画面から直接のアサイン変更はしない。
 */
class AssignSheetController extends Controller
{
    /** 役割コード → 表示ラベル（P列）。assignments.role の値に対応（AssignBoardController と揃える）。 */
    private const POS_LABELS = [
        'D' => 'D', 'SD' => 'SD', 'MC' => 'MC', 'OP' => 'OP',
        'FC' => 'FC', 'CK' => 'CK', 'RP' => '受付', 'SP' => '軍師・サポーター',
    ];

    /** メンバーを並べる優先順（Excelの並び＝D→SD→MC→OP→…に合わせる）。 */
    // メンバーの並び順＝上から D → (SD) → MC → OP → FC → CK → 軍師/サポ(SP/GUN) → 受付(RP/UKE)。
    private const POS_PRIORITY = ['D', 'SD', 'MC', 'OP', 'FC', 'CK', 'SP', 'GUN', 'RP', 'UKE'];

    /** 曜日（日本語）。0=日 〜 6=土。 */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** 種別キー → 表示ラベル（日付ヘッダーの色分け）。実施形態(format)から判定する。 */
    private const TYPE_LABELS = [
        'real'   => 'リアル',
        'long'   => 'リアルロング',
        'online' => 'オンライン',
        'basho'  => '場所貸し',
        'tokyo'  => '他拠点⇒東',
        'help'   => 'ヘルプのみ',
        'taiken' => '体験会',
        'other'  => '',
    ];

    public function index(Request $request)
    {
        // 対象になり得る案件＝完了/下書き以外で、開催日がある案件。
        $projects = Project::with(['director:id,name', 'goodsOwner:id,name'])
            ->orderBy('start_date')
            ->get()
            ->filter(fn (Project $p) => $p->start_date && ! in_array($p->status, ['完了', '下書き'], true))
            ->values();

        // 月の選択肢（案件のある年月だけ）。value='2026-07' / label='2026年7月'。
        $months = $projects
            ->map(fn (Project $p) => $p->start_date->format('Y-m'))
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $ym) => [
                'value' => $ym,
                'label' => Carbon::createFromFormat('Y-m-d', $ym . '-01')->format('Y') . '年'
                    . (int) substr($ym, 5, 2) . '月',
            ]);

        // 表示する月を決める。?month=YYYY-MM が選択肢にあればそれ、無ければ「今月以降で最初の月」。
        $monthValues = $months->pluck('value')->all();
        $requested = (string) $request->query('month', '');
        $current = Carbon::now()->format('Y-m');
        if (in_array($requested, $monthValues, true)) {
            $selectedMonth = $requested;
        } else {
            $selectedMonth = collect($monthValues)->first(fn ($m) => $m >= $current)
                ?? (end($monthValues) ?: $current);
        }

        // 選んだ月の案件だけに絞る。
        $monthProjects = $projects
            ->filter(fn (Project $p) => $p->start_date->format('Y-m') === $selectedMonth)
            ->values();

        // メンバー（assignments・キャンセル除く）をまとめて引く。
        $projectIds = $monthProjects->pluck('id');
        $assignments = $projectIds->isEmpty()
            ? collect()
            : Assignment::whereIn('project_id', $projectIds)
                ->where('status', '!=', 'キャンセル')
                ->get(['project_id', 'staff_id', 'role', 'status', 'note', 'patrol']);

        // 関係する人の名前・区分をまとめて引く（毎回引かない）。
        $people = Person::whereIn('id', $assignments->pluck('staff_id')->unique()->all())
            ->get(['id', 'name', 'role'])
            ->keyBy('id');

        $membersByProject = $assignments->groupBy('project_id');

        // コンテンツID → 名前（見出し用）。
        $contentNames = Content::pluck('content_name', 'id');

        // ポジション別の「担当内訳」（備考／巡回）の元データをまとめて引く（必要アサイン人数リスト由来）。
        // 案件ごとに content_ids × 規模 で絞れるよう、月内の全コンテンツ分を一括で取っておく（毎回引かない）。
        $allContentIds = $monthProjects
            ->flatMap(fn (Project $p) => is_array($p->content_ids) ? $p->content_ids : [])
            ->filter()->unique()->values();
        $reqByContent = $allContentIds->isEmpty()
            ? collect()
            : ContentRoleRequirement::whereIn('content_id', $allContentIds->all())
                ->where('count', '>', 0)
                ->orderBy('sort_order')
                ->get(['content_id', 'scale', 'position', 'count', 'note', 'patrol'])
                ->groupBy('content_id');

        $cards = $monthProjects->map(function (Project $p, int $i) use ($membersByProject, $people, $contentNames, $reqByContent) {
            // メンバー行（assignments → {name, pos, status, type}）。Dが先頭に来るよう優先順で並べる。
            $members = ($membersByProject->get($p->id) ?? collect())
                ->map(function ($a) use ($people) {
                    $person = $people->get($a->staff_id);

                    return [
                        'name' => $person->name ?? $a->staff_id,
                        'staffId' => $a->staff_id,
                        'roleCode' => $a->role ?: '',
                        'pos' => self::POS_LABELS[$a->role] ?? ($a->role ?: '—'),
                        'note' => $a->note ?? '',   // 担当メモ（軍師/サポ 等）
                        'patrol' => $a->patrol,     // 巡回数（数値/null）
                        'status' => $a->status,   // 仮/確定
                        'type' => ($person && $person->role === 'employee') ? 'emp' : 'staff',
                    ];
                })
                ->sortBy(fn ($m) => $this->posRank($m['roleCode']))
                ->values();

            // 案件にディレクター(director_id)が居るのに D の割当行が無いときは、D行として補う（Excelでは必ずDが並ぶため）。
            $hasDirectorRow = $members->contains(fn ($m) => $m['staffId'] === $p->director_id);
            if ($p->director_id && $p->director && ! $hasDirectorRow) {
                $members->prepend([
                    'name' => $p->director->name,
                    'staffId' => $p->director_id,
                    'roleCode' => 'D',
                    'pos' => 'D',
                    'note' => '',
                    'patrol' => null,
                    'status' => null,
                    'type' => 'emp',
                ]);
            }

            // 見出しコンテンツ名（複数あれば「／」でつなぐ）。無ければ案件名。
            $cids = is_array($p->content_ids) ? $p->content_ids : [];
            $contentLabels = collect($cids)
                ->map(fn ($id) => $contentNames[$id] ?? null)
                ->filter()
                ->values();
            $content = $contentLabels->isNotEmpty() ? $contentLabels->implode('／') : $p->project_name;

            $sales = is_array($p->sales_owners) ? implode('・', $p->sales_owners) : '';

            return [
                'id'          => $p->id,
                'no'          => $i + 1,
                'date'        => $this->fmtDate($p->start_date),
                'dayType'     => $p->date_type ?? '本番',
                'category'    => $p->category ?? '',
                'lodging'     => $this->clean($p->lodging),
                'content'     => $content,
                'scale'       => $this->clean($p->scale),
                'sales'       => $sales,
                'onlineTool'  => $this->clean($p->online_tool),
                'broadcast'   => $this->clean($p->broadcast),
                'client'      => $this->clean($p->client),
                'agency'      => $this->clean($p->agency),
                'operationPlace' => $this->clean($p->operation_place),
                'isMulti'     => (bool) $p->is_multi,
                'meet'        => $this->clean($p->start_time),
                'leave'       => $this->clean($p->end_time),
                'enter'       => $this->clean($p->event_enter_time),
                'evStart'     => $this->clean($p->event_start_time),
                'evEnd'       => $this->clean($p->event_end_time),
                'guests'      => $p->guest_count !== null ? (string) $p->guest_count : '',
                'teams'       => $p->team_count !== null ? (string) $p->team_count : '',
                'need'        => $p->required_count !== null ? (string) $p->required_count : '',
                'format'      => $this->clean($p->format),
                'typeKey'     => $this->classifyType($p->format),                 // 日付ヘッダーの色分け用
                'typeLabel'   => self::TYPE_LABELS[$this->classifyType($p->format)] ?? '',
                'staffRole'   => $this->clean($p->staff_role),
                'lineSent'    => (bool) $p->prep_line_sent,
                'handover'    => (bool) $p->prep_handover,
                'script'      => (bool) $p->prep_script,
                'opsSheet'    => $this->clean($p->ops_sheet_url),
                'audio'       => $this->clean($p->audio_equipment),
                'logo'        => $this->clean($p->pub_logo),
                'camera'      => $this->clean($p->pub_camera),
                'article'     => $this->clean($p->pub_article),
                'video'       => $this->clean($p->pub_video),
                'location'    => $this->clean($p->location),
                'assembly'    => $this->clean($p->assembly_type),
                'alcohol'     => $p->alcohol === null ? '' : ($p->alcohol ? '有' : '無'),
                'goods'       => $p->goodsOwner->name ?? '',
                'catering'    => $this->clean($p->catering),
                'transport'   => $this->clean($p->transport),
                'note'        => $this->clean($p->note),
                'roleDetail'  => $this->roleDetailText($p, $reqByContent),
                'need_i'      => (int) ($p->required_count ?? 0),
                'filled'      => $members->count(),
                'members'     => $members->all(),
            ];
        })->values();

        // 役割プルダウンの選択肢（正本）と、担当メモ候補（この月のコンテンツで使われる備考）。編集UIで使う。
        $noteOptions = $reqByContent
            ->flatten(1)
            ->pluck('note')
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('assign_sheet', [
            'cards'         => $cards,
            'months'        => $months,
            'selectedMonth' => $selectedMonth,
            'roleOptions'   => AssignmentRole::positionLabels(),
            'noteOptions'   => $noteOptions,
        ]);
    }

    /**
     * 案件の「担当内訳」を短い1行テキストにする（例：「OP 巡回×2　SP 軍師×1」）。
     *
     * 元データは content_role_requirements の note（備考）・patrol（巡回）。役割×備考でまとめる。
     * 備考も巡回も無い枠（Dなど指定なし）は出さない＝ポジション枠の数字で足りるため。
     * 考え方はアサイン画面（/project-assign）の「担当の内訳」と同じ。カードが狭いので短くまとめる。
     */
    private function roleDetailText(Project $p, Collection $reqByContent): string
    {
        $contentIds = is_array($p->content_ids) ? array_filter($p->content_ids) : [];
        $scale = $p->scale;
        if (empty($contentIds) || ! $scale) {
            return '';
        }

        // 役割 → 「備考|巡回」キー → 件数 を集計（同じ備考は合算）。
        $agg = [];
        foreach ($contentIds as $cid) {
            foreach ($reqByContent->get($cid, collect()) as $r) {
                if ((string) $r->scale !== (string) $scale || ! AssignmentRole::isValid($r->position)) {
                    continue;
                }
                $note = trim((string) ($r->note ?? ''));
                $patrol = $r->patrol;
                if ($note === '' && $patrol === null) {
                    continue;   // 指定なしの枠は内訳に出さない（枠の数字で十分）
                }
                $key = $note . '|' . ($patrol ?? '');
                if (! isset($agg[$r->position][$key])) {
                    $agg[$r->position][$key] = ['note' => $note, 'patrol' => $patrol, 'count' => 0];
                }
                $agg[$r->position][$key]['count'] += (int) $r->count;
            }
        }

        // 役割コードの並び順（LABELS の定義順＝D→SD→OP→…）にそろえて短い文にする。
        $parts = [];
        foreach (array_keys(AssignmentRole::LABELS) as $code) {
            if (empty($agg[$code])) {
                continue;
            }
            $items = [];
            foreach ($agg[$code] as $it) {
                $label = $it['note'] !== '' ? $it['note'] : '指定なし';
                if ($it['patrol'] !== null) {
                    $label .= '（巡回' . $it['patrol'] . '）';
                }
                $items[] = $label . '×' . $it['count'];
            }
            $parts[] = $code . ' ' . implode('・', $items);
        }

        return implode('　', $parts);
    }

    /** 開催日を「7/10（金）」の形にする。null は空。 */
    private function fmtDate(?Carbon $d): string
    {
        if (! $d) {
            return '';
        }

        return (int) $d->format('n') . '/' . (int) $d->format('j') . '（' . self::WEEKDAYS[(int) $d->format('w')] . '）';
    }

    /** 役割コードの並び順（小さいほど上）。未知の役割は末尾。 */
    private function posRank(string $code): int
    {
        $i = array_search($code, self::POS_PRIORITY, true);

        return $i === false ? 99 : $i;
    }

    /**
     * 実施形態(format)から日付ヘッダーの色分けキーを決める。
     * 例：「イベント東(リアルロング)」→long／「ARENA場所貸し」→basho／「イベント他拠点→東(巻き取り)」→tokyo。
     * どれにも当てはまらない（体験会・東→他拠点・ヘルプのみ・未設定）は other（既定色）。
     */
    private function classifyType(?string $format): string
    {
        $f = (string) $format;

        return match (true) {
            str_contains($f, '場所貸し') || str_contains($f, 'ARENA') => 'basho',
            str_contains($f, '他拠点→東') || str_contains($f, '他拠点⇒東') => 'tokyo',
            str_contains($f, 'ヘルプ') => 'help',
            str_contains($f, '体験') => 'taiken',
            str_contains($f, 'ロング') => 'long',
            str_contains($f, 'オンライン') => 'online',
            str_contains($f, 'リアル') => 'real',
            default => 'other',
        };
    }

    /** 実体のない記号（—/ー/-）は空欄にそろえる。表示のノイズを消す。 */
    private function clean(?string $v): string
    {
        $s = trim((string) $v);

        return in_array($s, ['—', 'ー', '-', '（未定）'], true) ? '' : $s;
    }
}
