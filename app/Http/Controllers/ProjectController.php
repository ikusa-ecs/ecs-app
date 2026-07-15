<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 案件一覧（/projects）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）をブラウザで読んでいた。
 * ここでは DB の projects テーブルを読み、cases.js と「同じ形」のデータ（ECS_CASES）
 * に詰め替えて Blade に渡す。表を組み立てる JavaScript はそのまま使えるので、
 * 見た目を変えずにデータの出どころだけ DB に切り替えられる。
 */
class ProjectController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // コンテンツID → 名前（見出し用）。1回だけ引いて連想配列にしておく。
        $contentNames = Content::pluck('content_name', 'id');

        // ディレクター・物品担当の名前は people を一緒に読む（毎回引かないようにする）。
        $projects = Project::with(['director:id,name', 'goodsOwner:id,name'])
            ->orderBy('start_date')
            ->get();

        // cases.js と同じ項目名に詰め替える（既存の表示JSをそのまま動かすため）。
        $cases = $projects->map(function (Project $p) use ($today, $contentNames) {
            // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。cases.js の off と同じ意味。
            $off = $p->start_date
                ? intdiv(
                    $p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp,
                    86400
                )
                : 0;

            // 見出し＝登録されたコンテンツ名（複数あれば先頭）。無ければ案件名で代用。
            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId
                ? ($contentNames[$firstContentId] ?? $p->project_name)
                : $p->project_name;

            return [
                'id'         => $p->id,
                'content'    => $content,
                'name'       => $p->project_name,
                'client'     => $p->client ?? '',
                'place'      => $p->location ?? '',
                'placeShort' => $p->location ?? '',
                'area'       => $p->operation_place ?? '',   // 運営場所（エリア）。アサイン表書き出し用
                'meetPlace'  => $p->assembly_type ?? '',      // 集合形式。アサイン表書き出し用
                'need'       => $p->required_count ?? '',      // 運営人数（必要人数）。アサイン表書き出し用
                'category'   => $p->category ?? '',
                'toc'        => (bool) $p->is_toc,     // toC（一般消費者向け）＝一覧の絞り込み用
                'yomi'       => $p->yomi ?? '',
                'format'     => $p->format ?? '',
                'scale'      => $p->scale ?? '',
                // セールス担当（複数あれば先頭）。未保存なら「—」。
                'sales'      => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
                'dir'        => $p->director->name ?? '未定',
                'goods'      => $p->goodsOwner->name ?? '未定',
                'sd'         => '未設定',                   // SD担当は未保存（後の工程で対応）。固定「なし」は嘘なので「未設定」に
                'meet'       => $p->start_time ?? '—',
                'leave'      => $p->end_time ?? '—',
                'enter'      => $p->event_enter_time ?? '—',
                'evStart'    => $p->event_start_time ?? '—',
                'evEnd'      => $p->event_end_time ?? '—',
                'guests'     => $p->guest_count ?? '—',
                'teams'      => $p->team_count ?? '—',
                'transport'  => $p->transport ?? 'ー',
                'sound'      => $p->audio_equipment ?? '',
                'lodging'    => $p->lodging ?? '無',
                'dayType'    => $p->date_type ?? '本番',
                'parentId'   => $p->parent_project_id,
                'recruit'    => (bool) $p->is_recruiting,
                'status'     => $p->status ?? '未着手',
                'tentative'  => (bool) $p->count_tentative,
                'lineSent'   => (bool) $p->prep_line_sent,
                'handover'   => (bool) $p->prep_handover,
                'script'     => (bool) $p->prep_script,
                'opSheet'    => $p->ops_sheet_url ?? '',
                'note'       => $p->note ?? '',
                // 開いた詳細で条件表示するための項目（第1弾）。
                'catering'   => $p->catering ?? '',        // 「無し」以外のとき詳細に表示
                'cateringNote' => $p->catering_note ?? '', // ケータリングの内容・時間・食数などのメモ
                'agency'     => $p->agency ?? '',          // 代理店ありのとき「企業名（代理店名）」表示
                'logo'       => $p->pub_logo ?? '',        // 「−」以外のとき詳細に表示
                'camera'     => $p->pub_camera ?? '',
                'article'    => $p->pub_article ?? '',
                'video'      => $p->pub_video ?? '',
                // cases.js では下書き・アーカイブは別フラグ。DB では status にまとめているので戻す。
                'draft'      => $p->status === '下書き',
                'archived'   => $p->status === '完了',
                'off'        => $off,
            ];
        })->values();

        // リピート（常連）クライアント＝同じクライアント名で案件が2件以上あるお客様。
        // クライアント名（前後空白は落とす）→ true の連想にして渡す（一覧でバッジ＋履歴リンクに使う）。
        $repeatClients = $projects
            ->map(fn (Project $p) => trim((string) $p->client))
            ->filter(fn ($c) => $c !== '')
            ->countBy()
            ->filter(fn ($n) => $n >= 2)
            ->keys()
            ->mapWithKeys(fn ($c) => [$c => true])
            ->all();

        return view('projects', [
            'cases'         => $cases,
            'repeatClients' => $repeatClients,
        ]);
    }

    /**
     * 案件登録／編集フォーム（GET /project-form）。
     * ?project=<案件ID> が来たら既存案件を DB から読み、各欄に埋める用のデータ（$editProject）を渡す。
     * 来なければ $editProject = null ＝ 新規登録フォームとして開く。
     * 流し込めるのは store() が実際に保存している項目だけ（ロゴ等のモック項目は対象外）。
     */
    public function form(Request $request)
    {
        $editProject = null;
        $projectId = $request->query('project');

        if ($projectId) {
            $p = Project::find($projectId);
            if ($p) {
                // content_ids（ID配列）→ コンテンツ名の配列に戻す（タグ入力に表示するため）。
                $contentNames = [];
                if (is_array($p->content_ids) && $p->content_ids) {
                    $map = Content::whereIn('id', $p->content_ids)->pluck('content_name', 'id');
                    foreach ($p->content_ids as $cid) {
                        if (isset($map[$cid])) {
                            $contentNames[] = $map[$cid];
                        }
                    }
                }

                $editProject = [
                    'id'               => $p->id,
                    'content_names'    => $contentNames,
                    'category'         => $p->category,
                    'is_toc'           => (bool) $p->is_toc,
                    'yomi'             => $p->yomi,
                    'yomi_expected'    => $p->yomi_expected,
                    'scale'            => $p->scale,
                    'is_recruiting'    => (bool) $p->is_recruiting,
                    'is_multi'         => (bool) $p->is_multi,
                    'date_type'        => $p->date_type,
                    'parent_project_id' => $p->parent_project_id,
                    'sales_owner'      => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '') : '',
                    'agency'           => $p->agency,
                    'format'           => $p->format,
                    'online_tool'      => $p->online_tool,
                    'base_locations'   => is_array($p->base_locations) ? $p->base_locations : [],
                    'broadcast'        => $p->broadcast,
                    'operation_place'  => $p->operation_place,
                    'arena_options'    => $p->arena_options,   // {setup_prev:..,...} or null
                    'client'           => $p->client,
                    'start_date'       => optional($p->start_date)->format('Y-m-d'),
                    'start_time'       => $p->start_time,
                    'end_time'         => $p->end_time,
                    'event_enter_time' => $p->event_enter_time,
                    'event_start_time' => $p->event_start_time,
                    'event_end_time'   => $p->event_end_time,
                    'location'         => $p->location,
                    'is_outdoor'       => $p->is_outdoor,   // true=屋外 / false=屋内 / null=未設定
                    'lodging'          => $p->lodging,
                    'assembly_type'    => $p->assembly_type,
                    'staff_role'       => $p->staff_role,
                    'required_count'   => $p->required_count,
                    'count_tentative'  => (bool) $p->count_tentative,
                    'guest_count'      => $p->guest_count,
                    'guest_count_type' => $p->guest_count_type,
                    'team_count'       => $p->team_count,
                    'team_tentative'   => (bool) $p->team_tentative,
                    'is_repeat'        => (bool) $p->is_repeat,
                    'alcohol'          => $p->alcohol,      // true=あり / false=なし / null=未設定
                    'catering'         => $p->catering,
                    'audio_equipment'  => $p->audio_equipment,
                    'transport'        => $p->transport,
                    'pub_logo'         => $p->pub_logo,
                    'pub_camera'       => $p->pub_camera,
                    'pub_article'      => $p->pub_article,
                    'pub_video'        => $p->pub_video,
                    'ops_sheet_url'    => $p->ops_sheet_url,
                    'note'             => $p->note,
                    'status'           => $p->status,
                ];
            }
        }

        // 「紐づく本番案件」の選択肢＝本物の本番案件（date_type=本番）。新規でも編集でも渡す。
        // 自分自身は除く（予備日が自分を親にしないように）。
        $parentProjects = Project::where('date_type', '本番')
            ->when($projectId, fn ($q) => $q->where('id', '!=', $projectId))
            ->orderBy('start_date')
            ->get()
            ->map(fn (Project $p) => [
                'id'    => $p->id,
                'label' => trim(($p->project_name ?: '（名称未定）')
                    . ($p->start_date ? '（' . $p->start_date->format('n/j') . '）' : '')),
            ])
            ->values();

        // 営業担当プルダウン用：社員（role=employee）の名前一覧（名前順）。
        $salesOwners = Person::employees()
            ->orderBy('name')
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return view('project_form', [
            'editProject'    => $editProject,
            'parentProjects' => $parentProjects,
            'salesOwners'    => $salesOwners,
            // アサインMTG日の予定表（/settings で保存）から計算した「基準日」＝今日までで一番新しいMTG日。
            // 開催日がこの日より後の登録を自動で「追加案件」に。予定が無ければ null（自動判定しない）。
            'assignMtgDate'  => \App\Support\AssignMtg::current(),
        ]);
    }

    /**
     * 案件登録フォームの保存（新規作成 または 上書き更新）。
     * フォーム（project_form.blade.php）から POST されたデータを projects テーブルに入れる。
     * project_id が来ていれば該当案件を更新、無ければ新規作成。
     * intent: 'draft'＝下書き保存／'publish'＝募集中にして保存。
     */
    public function store(Request $request)
    {
        // 空欄（''）は null に揃える（数値・日付のチェックを通すため）。
        $request->merge(
            collect($request->except('_token'))
                ->map(fn ($v) => $v === '' ? null : $v)
                ->all()
        );

        $request->validate([
            'start_date' => ['nullable', 'date'],
        ]);

        $publish = $request->input('intent') === 'publish';

        // 編集モード：project_id が来ていれば、その案件を上書き更新する（無ければ新規）。
        $editing = $request->filled('project_id')
            ? Project::find($request->input('project_id'))
            : null;

        // コンテンツ名 → コンテンツマスタ（無ければ新規発番して作る）→ content_ids
        $names = collect(explode(',', (string) $request->input('content_names')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->values();

        $contentIds = $this->resolveContentIds($names);

        $projectName = $names->isNotEmpty() ? $names->implode('・') : '（コンテンツ未定）';

        // 案件ID：編集なら既存IDを維持（変えると他の記録と紐づかなくなる）。新規は発番。
        $id = $editing ? $editing->id : $this->nextProjectId($request->input('start_date'));

        // 日程種別：予備日・リハのチェックがあればその種別。無ければ「本番」。
        $dateType = $request->has('has_sub')
            ? ($request->input('date_type_sub') ?: '本番')
            : '本番';

        // status の決め方。新規＝確定で「未着手」/下書きで「下書き」。
        // 編集＝確定は下書きのときだけ「未着手」に進める（進行中の状態は壊さない）。下書き保存は「下書き」へ。
        if ($editing) {
            $status = $publish
                ? ($editing->status === '下書き' ? '未着手' : $editing->status)
                : '下書き';
        } else {
            $status = $publish ? '未着手' : '下書き';
        }

        // ARENA場所貸しのときだけ、IKUSA側で対応する7項目（あり/なし）をまとめて保存する。
        $isArena = str_contains((string) $request->input('format'), 'ARENA');
        $arenaOptions = $isArena ? [
            'setup_prev'  => $request->input('arenaSetupPrev'),
            'light_setup' => $request->input('arenaLightSetup'),
            'mc'          => $request->input('arenaMc'),
            'av_staff'    => $request->input('arenaAvStaff'),
            'layout'      => $request->input('arenaLayout'),
            'broadcast'   => $request->input('arenaBroadcast'),
            'meal'        => $request->input('arenaMeal'),
        ] : null;

        // 紐づく本番案件は「予備日・リハ日として登録する」にチェックがあるときだけ意味を持つ。
        $parentId = $request->has('has_sub')
            ? ($request->input('parent_project_id') ?: null)
            : null;

        $attributes = [
            'project_name' => $projectName,
            'content_ids' => $contentIds,
            'category' => $request->input('addtl'),
            'is_toc' => $request->has('is_toc'),   // toC（一般消費者向け）チェック
            'yomi' => $request->input('yomi'),
            'yomi_expected' => $request->input('yomi_expected'),
            'scale' => $request->input('scale'),
            'is_recruiting' => ! $request->has('noRecruit'),  // 「募集しない」未チェック＝募集する
            'is_multi' => $request->input('multi') === 'あり',
            'date_type' => $dateType,
            'parent_project_id' => $parentId,
            'sales_owners' => $request->filled('sales_owner') ? [$request->input('sales_owner')] : null,
            'agency' => $request->input('agency'),
            'format' => $request->input('format'),
            // オンラインツールは実施形態が「オンライン」のときだけ保存（それ以外は初期値zoomが残らないよう null）。
            'online_tool' => str_contains((string) $request->input('format'), 'オンライン')
                ? $request->input('onlineTool')
                : null,
            'base_locations' => $request->input('baseLocation') ?: null,
            'broadcast' => $request->input('broadcast'),
            'operation_place' => $request->input('operation_place'),
            'arena_options' => $arenaOptions,
            'client' => $request->input('client'),
            'start_date' => $request->input('start_date'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'event_enter_time' => $request->input('event_enter_time'),
            'event_start_time' => $request->input('event_start_time'),
            'event_end_time' => $request->input('event_end_time'),
            'location' => $request->input('location'),
            'is_outdoor' => $request->filled('outdoor') ? ($request->input('outdoor') === '屋外') : null,
            'lodging' => $request->input('lodging'),
            'assembly_type' => $request->input('assembly_type'),
            'staff_role' => $request->input('staff_role'),
            'required_count' => $request->filled('required_count') ? (int) $request->input('required_count') : null,
            'count_tentative' => $request->has('count_tentative'),
            'guest_count' => $request->filled('guest_count') ? (int) $request->input('guest_count') : null,
            'guest_count_type' => $request->input('guestCount'),
            'team_count' => $request->filled('team_count') ? (int) $request->input('team_count') : null,
            'team_tentative' => $request->has('team_tentative'),
            'is_repeat' => $request->has('is_repeat'),
            'alcohol' => $request->filled('alcohol') ? ($request->input('alcohol') === 'あり') : null,
            'catering' => $request->input('catering'),
            'audio_equipment' => $request->input('audio_equipment'),
            'transport' => $request->input('transport'),
            'pub_logo' => $request->input('pub_logo'),
            'pub_camera' => $request->input('pub_camera'),
            'pub_article' => $request->input('pub_article'),
            'pub_video' => $request->input('pub_video'),
            'ops_sheet_url' => $request->input('ops_sheet_url'),
            'note' => $request->input('note'),
            // status はアサインの進み具合（上で編集／新規を考慮して決めた値）。
            'status' => $status,
        ];

        if ($editing) {
            // 上書き更新。公開状態（staff_published）は触らず維持する。
            $editing->update($attributes);
            $message = "案件「{$projectName}」を更新しました。（ID: {$id}）";
        } else {
            // 新規作成。IDを発番し、登録直後は非公開（公開はボードで担当が行う）。
            $attributes['id'] = $id;
            $attributes['staff_published'] = false;
            Project::create($attributes);
            $label = $publish ? '「募集中」で' : '下書きとして';
            $message = "案件「{$projectName}」を{$label}保存しました。（ID: {$id}）";
        }

        return redirect('/projects')->with('status', $message);
    }

    /**
     * ケータリングの種類・メモを DB に保存する（案件一覧の詳細から・POST /projects/catering）。
     * 公開ボードの時間保存と同じ「1項目だけ更新」のやり方。送られた項目だけ書き換える。
     * catering ＝「無」や空なら実質「なし」。catering_note ＝内容・時間・食数などの自由メモ。
     */
    public function saveCatering(Request $request)
    {
        $data = $request->validate([
            'id'            => ['required', 'string'],
            'catering'      => ['sometimes', 'nullable', 'string', 'max:50'],
            'catering_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $project = Project::findOrFail($data['id']);
        if ($request->has('catering')) {
            $project->catering = trim((string) $request->input('catering')) ?: null;
        }
        if ($request->has('catering_note')) {
            $project->catering_note = trim((string) $request->input('catering_note')) ?: null;
        }
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * 案件の削除（POST /projects/{id}/delete）。
     * キャンセルになったイベントの案件を一覧から消すための機能。ログイン済みの社員以上が実行できる
     * （誰が実行できるかはルート側で制御済み。ここでは触らない）。
     * 案件だけ消すと、その案件に紐づくアサイン（assignments.project_id）や、複数日案件の予備日・リハ日
     * （parent_project_id でこの案件を親に指す子案件）が宙に浮いて残ってしまう。
     * そこでトランザクションでまとめて「子案件も含めたアサイン → 案件本体（子案件も）」の順に削除する。
     */
    public function destroy($id)
    {
        // 案件IDは文字列（例 P-2026-0001）。無ければ落とさず一覧へ戻して知らせる。
        $project = Project::find($id);
        if (! $project) {
            return redirect('/projects')
                ->with('status', '指定の案件が見つかりませんでした（すでに削除された可能性があります）。');
        }

        // メッセージ用の表示名。未設定でも壊れないように代替名を用意する。
        $name = $project->project_name ?: '（名称未定）';

        // この案件を親に持つ子案件（予備日・リハ日）のIDも集めて、一緒に消す対象にする。
        $childIds = Project::where('parent_project_id', $id)->pluck('id')->all();
        $targetIds = array_merge([$id], $childIds);

        DB::transaction(function () use ($targetIds) {
            // 先にアサインを消す（割り当てが宙に浮いて残らないように）。
            Assignment::whereIn('project_id', $targetIds)->delete();
            // そのあとで案件本体（子案件も含む）を消す。
            Project::whereIn('id', $targetIds)->delete();
        });

        return redirect('/projects')->with('status', "案件「{$name}」を削除しました。");
    }

    /**
     * CSV一括取込（project_import.blade.php → POST /project-import）。
     * 記入済みCSVを読み、1行ずつチェックして OK 行だけを projects に登録する。
     * チェック基準は画面側の JS と同じ（案件名・開催日の形式・運営人数の3必須）。
     * エラー行は登録しない。終わったら案件一覧へ件数サマリ付きで戻る。
     */
    public function import(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        // CSV を行配列にする（BOM除去・CRLF対応）。
        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);          // 先頭BOMを除去
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        if (count($lines) < 2) {
            return redirect('/project-import')
                ->with('import_error', 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        // 見出し行 → 列名→位置 の対応表。テンプレートの列名で引けるようにする。
        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $col = array_flip(array_map('trim', $header));
        $get = function (array $row, string $name) use ($col) {
            $i = $col[$name] ?? null;
            return ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
        };

        $okCount = 0;
        $errors = [];   // 「2行目：開催日の形式が…」のような説明を貯める
        $lineNo = 1;    // 見出しを除いた人間向けの行番号（1始まり）

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue; // 空行はスキップ
            }
            $lineNo++;
            $row = str_getcsv($line, ',', '"', '');

            $name = $get($row, '案件名');
            $date = $get($row, '開催日');
            $count = $get($row, '運営人数');

            // --- 必須3項目のチェック（JSの validate と同じ基準）---
            $rowErrors = [];
            if ($name === '') {
                $rowErrors[] = '案件名が空です';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->isRealDate($date)) {
                $rowErrors[] = '開催日の形式が不正です（例 2026-07-20）';
            }
            if ($count === '' || ! ctype_digit($count) || (int) $count < 1) {
                $rowErrors[] = '運営人数が空または不正です';
            }
            if ($rowErrors) {
                $errors[] = "{$lineNo}行目（{$name}）：" . implode('／', $rowErrors);
                continue;
            }

            // --- OK行を登録 ---
            $contentName = collect([$name])->filter()->unique()->values();
            $contentIds = $this->resolveContentIds($contentName);

            Project::create([
                'id' => $this->nextProjectId($date),
                'project_name' => $name,
                'content_ids' => $contentIds,
                'category' => $get($row, '区分') ?: null,
                // toC列に「toC/toc/あり/○/はい/1」があれば一般消費者向け＝true。空欄はtoB扱い。
                'is_toc' => in_array($get($row, 'toC'), ['toC', 'toc', 'あり', '○', '◯', 'はい', '1'], true),
                'yomi' => $get($row, '確度') ?: null,
                'scale' => $get($row, '案件規模') ?: null,
                'is_recruiting' => $get($row, 'スタッフ募集') !== '募集しない',
                'is_multi' => $get($row, '複数案件') === 'あり',
                'date_type' => $get($row, '日程種別') ?: '本番',
                'parent_project_id' => $get($row, '紐づく本番案件') ?: null,
                'sales_owners' => $get($row, '営業担当') ? [$get($row, '営業担当')] : null,
                'format' => $get($row, '実施形態') ?: null,
                'broadcast' => $get($row, '配信種別') ?: null,
                'operation_place' => $get($row, '運営場所') ?: null,
                'client' => $get($row, 'クライアント') ?: null,
                'agency' => $get($row, '代理店名') ?: null,
                'staff_role' => $get($row, '担当体制') ?: null,
                'start_date' => $date,
                'start_time' => $get($row, '集合時間') ?: null,
                'end_time' => $get($row, '解散時間') ?: null,
                'event_enter_time' => $get($row, 'イベント入場') ?: null,
                'event_start_time' => $get($row, 'イベント開始') ?: null,
                'event_end_time' => $get($row, 'イベント終了') ?: null,
                'location' => $get($row, '会場住所') ?: null,
                'is_outdoor' => $get($row, '屋内外') ? ($get($row, '屋内外') === '屋外') : null,
                'lodging' => $get($row, '宿泊') ?: null,
                'assembly_type' => $get($row, '集合形式') ?: null,
                'required_count' => (int) $count,
                'guest_count' => ctype_digit($get($row, 'お客様人数')) ? (int) $get($row, 'お客様人数') : null,
                'team_count' => ctype_digit($get($row, 'チーム数')) ? (int) $get($row, 'チーム数') : null,
                'is_repeat' => $get($row, 'リピート') === 'あり',
                'alcohol' => $get($row, 'お酒') ? ($get($row, 'お酒') === 'あり') : null,
                'catering' => $get($row, 'ケータリング') ?: null,
                'audio_equipment' => $get($row, '音響機材') ?: null,
                'transport' => $get($row, '移動車両') ?: null,
                'pub_logo' => $get($row, 'ロゴ') ?: null,
                'pub_camera' => $get($row, 'カメラ') ?: null,
                'pub_article' => $get($row, '事例記事') ?: null,
                'pub_video' => $get($row, '動画') ?: null,
                // ディレクター・物品担当は名前 → 名簿(people)から一致を探してID化（無ければ空欄）。
                'director_id' => $this->personIdByName($get($row, 'ディレクター')),
                'goods_owner_id' => $this->personIdByName($get($row, '物品担当')),
                'ops_sheet_url' => $get($row, '運営シートURL') ?: null,
                'prep_line_sent' => $get($row, '準備:LINE概要送付') === '済',
                'prep_handover' => $get($row, '準備:引き継ぎ') === '済',
                'prep_script' => $get($row, '準備:台本') === '済',
                'note' => $get($row, '備考') ?: null,
                'status' => '未着手',          // 取込分は「未着手」で登録
                'staff_published' => false,    // 取込直後は非公開（公開はボードで担当が行う）
            ]);
            $okCount++;
        }

        $msg = "CSVから{$okCount}件の案件を取り込みました。";
        if ($errors) {
            $msg .= ' エラー' . count($errors) . '件は取り込みませんでした：' . implode(' / ', $errors);
        }

        return redirect('/projects')->with('status', $msg);
    }

    /**
     * コンテンツ名の一覧 → content_ids（配列）。
     * マスタに無ければ CT-### を発番して新規作成する。store()/import() で共用。
     */
    private function resolveContentIds(Collection $names): array
    {
        return $names->map(function ($name) {
            $existing = Content::where('content_name', $name)->first();
            if ($existing) {
                return $existing->id;
            }
            // CT-### の空き番号を発番して新しいコンテンツを作る
            $maxNum = Content::all()
                ->map(fn ($c) => (int) preg_replace('/\D/', '', $c->id))
                ->max() ?? 0;
            $newId = 'CT-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
            Content::create([
                'id' => $newId,
                'content_name' => $name,
                'category' => 'その他',
                'is_physical' => false,
                'active' => true,
            ]);

            return $newId;
        })->all();
    }

    /**
     * 案件IDを発番：P-YYYY-NNNN（年は開催日。未定なら今年）。store()/import() で共用。
     */
    private function nextProjectId(?string $startDate): string
    {
        $year = $startDate ? Carbon::parse($startDate)->year : Carbon::now()->year;
        $prefix = 'P-' . $year . '-';
        $maxSeq = Project::where('id', 'like', $prefix . '%')->get()
            ->map(fn ($p) => (int) substr($p->id, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad($maxSeq + 1, 4, '0', STR_PAD_LEFT);
    }

    /** 名前 → people のID（社員・スタッフ問わず先頭一致1件）。無ければ null。 */
    private function personIdByName(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        return Person::where('name', $name)->value('id');
    }

    /** YYYY-MM-DD が実在する日付か（2026-13-40 などを弾く）。 */
    private function isRealDate(string $date): bool
    {
        [$y, $m, $d] = array_pad(explode('-', $date), 3, '0');

        return checkdate((int) $m, (int) $d, (int) $y);
    }
}

