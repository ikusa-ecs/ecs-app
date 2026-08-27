<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Office;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectHistory;
use App\Support\CsvText;
use App\Support\DirectorSync;
use App\Support\Headcount;
use App\Support\ProjectAccess;
use App\Support\ProjectImportColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
    /** 案件編集画面の下に出す編集履歴の件数（続きは /project-history で見る）。 */
    private const FORM_HISTORY_LIMIT = 20;

    public function index(Request $request)
    {
        $today = Carbon::today();

        // コンテンツID → 名前（見出し用）。1回だけ引いて連想配列にしておく。
        $contentNames = Content::pluck('content_name', 'id');

        // 拠点の表示範囲（全拠点運用・設計書19.2）。管理者以上はスイッチで選んだ拠点、
        // 一般社員は自拠点固定。null＝全拠点（絞らない）。現状は全員東京なので実質全件。
        $officeScope = \App\Support\OfficeScope::filter($request);

        // ログイン中の拠点と、コピー/巻き取りを操作できるか（＝アサイン担当＝管理者以上）。
        $myOffice = Auth::user()->office ?: '東京';
        $canManageShare = \App\Support\OfficeScope::canSeeAll();

        // ディレクター・SD・物品担当の名前は people を一緒に読む（毎回引かないようにする）。
        // 拠点で絞るときは「登録拠点がその拠点」＋「その拠点に共有された案件」も含める（アサイン表と同じ）。
        $projects = \App\Support\OfficeScope::applyToProjects(
            Project::with(['director:id,name', 'subDirector:id,name', 'goodsOwner:id,name']),
            $officeScope
        )
            ->orderBy('start_date')
            ->get();

        // この一覧に出る案件の「拠点間共有」（ヘルプ/巻き取り）をまとめて引く。
        $sharesByProject = $projects->isEmpty()
            ? collect()
            : \App\Models\ProjectShare::whereIn('project_id', $projects->pluck('id')->all())->get()->groupBy('project_id');

        // cases.js と同じ項目名に詰め替える（既存の表示JSをそのまま動かすため）。
        $cases = $projects->map(function (Project $p) use ($today, $contentNames, $sharesByProject, $myOffice, $canManageShare) {
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

            // 実効アーカイブ状態＝アーカイブタブの振り分けに使う「本物の隠す/表示」判定。
            // is_archived が null なら「開催日<今日」で自動アーカイブ。true/false なら手動を優先。
            $autoArchived = $p->start_date
                ? $p->start_date->copy()->startOfDay()->lt($today)
                : false;
            $effectiveArchived = is_null($p->is_archived)
                ? $autoArchived
                : (bool) $p->is_archived;

            return [
                'id'         => $p->id,
                'content'    => $content,
                'name'       => $p->project_name,
                'client'     => $p->client ?? '',
                'place'      => $p->location ?? '',
                'placeShort' => $p->location ?? '',
                'area'       => $p->operation_place ?? '',   // 運営場所（エリア）。アサイン表書き出し用
                'meetPlace'  => $p->assembly_type ?? '',      // 集合形式。アサイン表書き出し用
                // 運営人数。「6〜8」のような範囲もそのまま出せる形にして渡す（2026-08-25 baba）。
                'need'       => Headcount::label($p->required_count_min, $p->required_count),
                'category'   => $p->category ?? '',
                'toc'        => (bool) $p->is_toc,     // toC（一般消費者向け）＝一覧の絞り込み用
                'yomi'       => $p->yomi ?? '',
                'format'     => $p->format ?? '',
                'scale'      => $p->scale ?? '',
                // セールス担当（複数あれば先頭）。未保存なら「—」。
                'sales'      => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
                'dir'        => $p->director->name ?? '未定',
                'goods'      => $p->goodsOwner->name ?? '未定',
                'sd'         => $p->subDirector->name ?? '未設定',   // SD担当（DB保存）。未登録なら「未設定」
                'sdName'     => $p->subDirector->name ?? '',        // SDの名前（空＝未設定）
                // 詳細のプルダウンの現在値に使う社員ID（担当なしは null）。
                'director_id'    => $p->director_id,
                'sd_id'          => $p->sd_id,
                'goods_owner_id' => $p->goods_owner_id,
                'audio_equipment' => $p->audio_equipment ?? '',    // 音響（プルダウン初期値用）
                'meet'       => $p->start_time ?? '—',
                'leave'      => $p->end_time ?? '—',
                'enter'      => $p->event_enter_time ?? '—',
                'evStart'    => $p->event_start_time ?? '—',
                'evEnd'      => $p->event_end_time ?? '—',
                'evTbd'      => (bool) $p->event_time_tbd,   // 本番時間未定
                'guests'     => $p->guest_count ?? '—',
                'teams'      => $p->team_count ?? '—',
                'transport'  => $p->transport ?? 'ー',
                'sound'      => $p->audio_equipment ?? '',
                'lodging'    => $p->lodging ?? '無',
                'dayType'    => $p->date_type ?? '本番',
                'parentId'   => $p->parent_project_id,
                'recruit'    => (bool) $p->is_recruiting,
                'published'  => (bool) $p->staff_published,   // スタッフ公開ボードで「公開する」を押したか
                'status'     => $p->status ?? '未着手',
                'tentative'  => (bool) $p->count_tentative,
                'repeat'     => (bool) $p->is_repeat,      // 手で入れた「リピート案件」の印
                'lineSent'   => (bool) $p->prep_line_sent,
                'lineMade'   => (bool) $p->prep_line_created,
                'lineDouble' => (bool) $p->prep_line_double_check,
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
                // cases.js では下書き・アーカイブは別フラグ。下書きは status から、
                // アーカイブは上で出した実効アーカイブ状態（自動＝開催日／手動＝is_archived）を渡す。
                'draft'      => $p->status === '下書き',
                'archived'   => $effectiveArchived,
                'is_archived' => $p->is_archived,   // 生の手動状態（null=自動／true/false=手動）。参考用
                // キャンセル（2026-08-26）。実施形態のバッジを「キャンセル」に差し替えて出し、
                // チェックボックスで一覧から隠せる。実施形態の値は消していない（戻せる）。
                'cancelled'  => (bool) $p->is_cancelled,
                'off'        => $off,
                // ---- 拠点まわり（全拠点運用・設計書19.2）----
                'office'        => $p->office ?? '',                          // 登録拠点
                'sharedOffices' => $sharesByProject->get($p->id, collect())
                    ->map(fn ($s) => ['office' => $s->office, 'kind' => $s->kind])->values()->all(),
                'isOwn'         => ($p->office ?? '') === $myOffice,
                'sharedToMe'    => (bool) $sharesByProject->get($p->id, collect())->firstWhere('office', $myOffice),
                'myKind'        => optional($sharesByProject->get($p->id, collect())->firstWhere('office', $myOffice))->kind ?? 'ヘルプ',
                'canCopy'       => $canManageShare && ($p->office ?? '') !== '' && ($p->office ?? '') !== $myOffice
                    && ! $sharesByProject->get($p->id, collect())->firstWhere('office', $myOffice),
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

        // 詳細のプルダウン（D／SD／物品担当）に出す「本物の社員一覧」。
        // これまでの見本配列（DIRECTORS/SDLIST 等）の代わりに、この一覧で選ばせる。
        // 並びは「見ている拠点のイベプラ」を先頭に、あとは氏名順（Dに立つのはほぼ自拠点のイベプラ）。
        // ⚠ 「アサインの候補に出さない」社員はプルダウンに出さない（2026-08-26 baba要望）。
        //   いま担当に入っている人（D/SD/物品）は対象外でも残す＝プルダウンから消えると
        //   その案件のセルが空に見えてしまうため。
        $assignedIds = $projects
            ->flatMap(fn (Project $p) => [$p->director_id, $p->sd_id, $p->goods_owner_id])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $employees = Person::employees()
            ->inAssignPool($assignedIds)
            ->plannersOfOfficeFirst($officeScope ?: $myOffice)
            ->get(['id', 'name', 'office', 'department'])
            ->map(fn (Person $e) => ['id' => $e->id, 'name' => $e->name])
            ->values();

        return view('projects', [
            'cases'         => $cases,
            'repeatClients' => $repeatClients,
            'employees'     => $employees,
            // 拠点バッジは「全拠点」表示のときだけ出す（単体拠点なら自明・baba 2026-07-29）。
            'showOfficeBadge' => $officeScope === null,
            // コピー/巻き取り操作ができるか（アサイン担当＝管理者以上）。
            'canManageShare'  => $canManageShare,
            // 絞り込みの「拠点」プルダウンの並び（拠点マスタの順）。全拠点表示のときだけ画面に出す。
            'officeOptions'   => \App\Support\OfficeScope::options(),
            // 一覧の中で直せる「移動・車両」「音響機材」の選択肢。正本＝App\Support\OfficeOptions。
            // 案件ごとに登録拠点が違うので、全拠点ぶん渡して案件の拠点で引く（案件登録フォームと同じ渡し方）。
            // ※ 以前はこの画面だけ選択肢を直書きしていて、マスタ管理で拠点ごとに変えても反映されなかった。
            'officeOptionMap' => \App\Support\OfficeOptions::mapForAll(\App\Support\OfficeScope::options()),
            // 拠点が空・拠点マスタが未登録のときの受け皿。ここも直書きせず OfficeOptions から取る。
            'officeOptionDefaults' => \App\Support\OfficeOptions::DEFAULTS,
        ]);
    }

    /**
     * 案件登録／編集フォーム（GET /project-form）。
     * ?project=<案件ID> が来たら既存案件を DB から読み、各欄に埋める用のデータ（$editProject）を渡す。
     * 来なければ $editProject = null ＝ 新規登録フォームとして開く。
     * 流し込めるのは store() が実際に保存している項目だけ（ロゴ等のモック項目は対象外）。
     *
     * ?copy=<案件ID>（複製・先人の要件定義 #1）が来たときも中身の作り方は編集と同じ。
     * 違いは最後に「引き継がない項目」を落とすところだけ＝元の案件は一切変更しない（読むだけ）。
     * project_id を空で渡すので store() は新規作成になる（CSV取込の流し込みと同じ仕組み）。
     */
    public function form(Request $request)
    {
        $editProject = null;
        $projectId = $request->query('project');
        $copyId = $request->query('copy');
        $copyFrom = null;                       // 複製のとき、元になった案件の表示用の情報

        // 編集と複製で読む先は同じ。?project= が優先（両方来ることは想定しない）。
        $sourceId = $projectId ?: $copyId;

        if ($sourceId) {
            $p = Project::find($sourceId);
            if ($p) {
                // タグ入力に戻す案件名（コンテンツ）。
                // 入力された名前をそのまま持っていればそれを使う（単発コンテンツもここに入っている）。
                // 持っていない古い案件は、これまでどおり content_ids から名前を復元する。
                $contentNames = [];
                if (is_array($p->content_names) && $p->content_names) {
                    $contentNames = array_values(array_filter($p->content_names));
                } elseif (is_array($p->content_ids) && $p->content_ids) {
                    $map = Content::whereIn('id', $p->content_ids)->pluck('content_name', 'id');
                    foreach ($p->content_ids as $cid) {
                        if (isset($map[$cid])) {
                            $contentNames[] = $map[$cid];
                        }
                    }
                }

                // そのうち台帳に無いもの＝単発コンテンツ。画面で「単発」の印を付けて表示し、
                // 保存し直しても台帳に登録されないようにする。
                $inMaster = $contentNames
                    ? Content::whereIn('content_name', $contentNames)->pluck('content_name')->all()
                    : [];
                $oneOffNames = array_values(array_diff($contentNames, $inMaster));

                $editProject = [
                    'id'               => $p->id,
                    'content_names'    => $contentNames,
                    'oneoff_content_names' => $oneOffNames,
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
                    'office'           => $p->office,
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
                    'event_time_tbd'   => (bool) $p->event_time_tbd,
                    'location'         => $p->location,
                    'is_outdoor'       => $p->is_outdoor,   // true=屋外 / false=屋内 / null=未設定
                    'lodging'          => $p->lodging,
                    // 3択（自動/数える/数えない）は画面のラジオに合わせて文字で渡す。
                    'count_as_event'   => $p->count_as_event === null ? 'auto' : ($p->count_as_event ? 'yes' : 'no'),
                    'assembly_type'    => $p->assembly_type,
                    'assembly_detail'  => $p->assembly_detail,
                    'staff_belongings' => $p->staff_belongings,
                    'staff_dresscode'  => $p->staff_dresscode,
                    'staff_notes'      => $p->staff_notes,
                    'staff_role'       => $p->staff_role,
                    // 編集フォームには「6〜8」の形で戻す（入れたとおりに直せるように）。
                    'required_count'   => Headcount::label($p->required_count_min, $p->required_count),
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

                // ── 複製のときだけ、新規登録として開き直す ──
                // 引き継がない項目（2026-08-18 baba 了承）：
                //   ・案件ID … 空にすると store() が新しい案件として発番する（元の案件は無傷）
                //   ・開催日 … 同じ日で二重に登録する事故を防ぐため、必ず入れ直してもらう
                //   ・運営シートURL … 元の案件のシートを指したままにしない
                // ※ ステータスは持ち回らなくてよい。新規は「下書き保存」か「確定」かで store() が決めるため。
                if (! $projectId) {
                    $copyFrom = [
                        'id'   => $p->id,
                        'name' => $p->project_name ?: '（名称未定）',
                    ];
                    $editProject['id'] = null;
                    $editProject['start_date'] = '';
                    $editProject['ops_sheet_url'] = '';
                }
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

        // 営業担当プルダウン用：社員（role=employee）の名前一覧。
        // 並びは社歴順（入社日の古い人が上・未入力は末尾）＝baba要望 2026-08-24。
        $salesOwners = Person::employees()
            ->bySeniority()
            ->pluck('name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 登録拠点プルダウン用：拠点マスタ（有効なもの・並び順）。全拠点運用の土台（設計書19.2）。
        $offices = Office::where('active', true)->orderBy('sort_order')->pluck('name')->all();

        // 案件名（コンテンツ）の候補＝コンテンツ台帳（有効なもの）。
        // 以前は画面に12件ベタ書きで、台帳にあるコンテンツの多くが候補に出なかった（社内FBで指摘あり）。
        // 並びは「マスタ管理のコンテンツ台帳と同じ上からの順番」（並び順→ID順）。
        // ⚠ 以前はコンテンツ名の文字コード順だったため、台帳の見た目と並びが違っていた
        //   （baba指摘 2026-08-24。台帳の上＝CT-001 から出したい）。
        $contentOptions = Content::where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // 拠点ごとの選択肢（集合形式・音響機材・移動車両・運営場所）。
        // 正本＝App\Support\OfficeOptions（マスタ管理で拠点ごとに書き換えられる）。
        // 画面には全拠点ぶん渡す＝「登録拠点」を選び直した瞬間にプルダウンの中身を入れ替えるため。
        // ※ 以前は画面に直書きで、東京にしか無い「大住」「広宣」「IKUSAカー」が
        //   他拠点でも出ていた（2026-08-21 baba）。
        $officeOptionMap = \App\Support\OfficeOptions::mapForAll(\App\Support\OfficeScope::options());

        // 既定の拠点＝編集ならその案件の拠点／新規ならログイン中の社員の拠点（無ければ東京）。
        $defaultOffice = $editProject['office']
            ?? (Auth::user()->office ?? '東京');

        // 編集で開いたときだけ、この案件の変更履歴（先-1）を新しい順に少しだけ添える。
        // 全部見たいときは専用画面（/project-history）へ。複製で開いたときは元案件の履歴なので出さない。
        // ※ 保存先テーブルがまだ無いサーバー（migrate 未実行）でも、この画面が開けなくならないようにする。
        $canShowHistory = $projectId && \App\Support\ProjectHistoryRecorder::available();
        $histories = $canShowHistory
            ? ProjectHistory::where('project_id', $projectId)
                ->orderByDesc('id')
                ->limit(self::FORM_HISTORY_LIMIT)
                ->get()
            : collect();
        $historyTotal = $canShowHistory
            ? ProjectHistory::where('project_id', $projectId)->count()
            : 0;

        // ── 危険日（高負荷日）の判定に使う「日ごとの負荷」＝DBの実案件から作る ──
        // これまで画面は凍結モックの /ecs/data/cases.js を読んで同日の案件を数えていたため、
        // 実データでは警告が出なかった（CLAUDE.md でモックは凍結＝参照元にしない方針）。
        // 編集中の案件はここから外す（自分を二重に数えないため）。
        $dayLoad = $this->dayLoad($projectId);

        return view('project_form', [
            'editProject'    => $editProject,
            'dayLoad'        => $dayLoad,
            'histories'      => $histories,
            'historyTotal'   => $historyTotal,
            'historyLimit'   => self::FORM_HISTORY_LIMIT,
            // 複製で開いたときだけ中身が入る（元になった案件の ID と名前）。画面の案内文に使う。
            'copyFrom'       => $copyFrom,
            'parentProjects' => $parentProjects,
            'salesOwners'    => $salesOwners,
            'offices'        => $offices,
            'defaultOffice'  => $defaultOffice,
            'contentOptions' => $contentOptions,
            'officeOptionMap' => $officeOptionMap,
            // アサインMTG日の予定表（/settings で保存）から計算した「基準日」＝今日までで一番新しいMTG日。
            // 開催日がこの日より後の登録を自動で「追加案件」に。予定が無ければ null（自動判定しない）。
            // ⚠ MTG日は拠点ごとに違うので「拠点 → 基準日」でまとめて渡す（2026-08-26 baba要望）。
            //   画面で登録拠点を選び直すと、その拠点の基準日で判定し直す。
            'assignMtgByOffice' => \App\Support\AssignMtg::currentByOffice($offices),
        ]);
    }

    /**
     * 危険日の判定に使う「開催日 → その日の案件たち」。
     * 画面（危険日ヒント・保存前の確認）が cases.js のかわりに読む。
     *
     * 出す形＝['2026-09-20' => [['scale'=>'大型','fmt'=>'real','need'=>20,'name'=>'…'], ...]]
     * 下書き・手動アーカイブ・過去の案件は数えない（モックの ECS_casesOnDate と同じ考え方）。
     *
     * @param  string|null  $excludeId  編集中の案件（自分を二重に数えないため外す）
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function dayLoad(?string $excludeId = null): array
    {
        $today = Carbon::today();

        $rows = Project::whereNotNull('start_date')
            ->whereDate('start_date', '>=', $today->format('Y-m-d'))
            ->where('status', '!=', '下書き')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'project_name', 'start_date', 'scale', 'format', 'required_count', 'is_archived']);

        $out = [];
        foreach ($rows as $p) {
            if ($p->is_archived === true) {
                continue;
            }
            $key = $p->start_date->format('Y-m-d');
            $out[$key][] = [
                'scale' => (string) ($p->scale ?? ''),
                // 実施形態 → 画面の判定コード（online / long / real）。cases.js の ECS_fmtCode と同じ基準。
                'fmt'   => $this->formatCode((string) ($p->format ?? '')),
                'need'  => (int) ($p->required_count ?? 0),
                'name'  => (string) $p->project_name,
            ];
        }

        return $out;
    }

    /** 実施形態の文字 → 判定コード（cases.js の ECS_fmtCode と同じ基準にそろえる）。 */
    private function formatCode(string $format): string
    {
        if (mb_strpos($format, 'オンライン') !== false || mb_strpos($format, 'ヘルプのみ') !== false) {
            return 'online';
        }
        if (mb_strpos($format, 'リアルロング') !== false) {
            return 'long';
        }

        return 'real';
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

        // intent: 'draft'＝下書き／'publish'＝確定／'next'＝確定して「同じ内容で次の日程」へ続ける。
        $intent = (string) $request->input('intent');
        $publish = in_array($intent, ['publish', 'next'], true);

        // ── 「確定」で保存するときの必須チェック（サーバー側）──
        // これまでサーバー側は開催日の形式だけ見ており、画面のJSを通らない経路（直接POST・
        // JSが動かないブラウザ）だと案件名も人数も空で登録できた。CSV一括取込は同じ3項目を
        // サーバーで見ているので、フォームも同じ基準にそろえる。
        // 「未定」にチェックが入っているものは、これまでどおり空でも通す（下書きも通す）。
        if ($publish) {
            $errors = [];
            $hasContent = trim((string) $request->input('content_names')) !== ''
                || trim((string) $request->input('oneoff_content_names')) !== '';
            if (! $hasContent && ! $request->boolean('content_tbd')) {
                $errors['content_names'] = '案件名（コンテンツ）を選ぶか、「コンテンツ未定」にチェックを入れてください。';
            }
            if (! $request->filled('start_date') && ! $request->boolean('date_tbd')) {
                $errors['start_date'] = '開催日を入力するか、「日付未定」にチェックを入れてください。';
            }
            // 「6〜8人」のような範囲でもよい（2026-08-25 baba）。読み方は Headcount が正本。
            $count = Headcount::parse($request->input('required_count'))['max'] ?? 0;
            if ($count < 1 && ! $request->boolean('count_tentative')) {
                $errors['required_count'] = '運営人数を入力するか、「人数は仮（未定）」にチェックを入れてください。';
            }
            if ($errors) {
                return back()->withInput()->withErrors($errors);
            }
        }

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

        // 画面で「この案件だけで使う」を選んだ名前＝コンテンツ台帳には登録しない（単発コンテンツ）。
        // その案件限りのコンテンツで台帳が増え続けるのを防ぐため（社内要望・2026-08-04）。
        $oneOffNames = collect(explode(',', (string) $request->input('oneoff_content_names')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->unique()
            ->values();

        $contentIds = $this->resolveContentIds($names, $oneOffNames);

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
            // 入力された名前そのもの。台帳に登録しない単発コンテンツも、ここに残るので
            // あとで編集画面を開いたときに名前が消えない。
            'content_names' => $names->all(),
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
            // 登録拠点＝案件がどの拠点のものか（設計書19.2）。送信値を優先し、
            // 無ければ編集時は既存を維持／新規はログイン者の拠点（無ければ東京）。
            'office' => $request->input('office')
                ?: ($editing->office ?? (Auth::user()->office ?? '東京')),
            'format' => $request->input('format'),
            // オンラインツールは実施形態が「オンライン」のときだけ保存（それ以外は初期値zoomが残らないよう null）。
            'online_tool' => str_contains((string) $request->input('format'), 'オンライン')
                ? $request->input('onlineTool')
                : null,
            'base_locations' => $request->input('baseLocation') ?: null,
            'broadcast' => $request->input('broadcast'),
            'operation_place' => $request->input('operation_place'),
            'arena_options' => $arenaOptions,
            // クライアント名は書き方をそろえて保存する（末尾の「様」「御中」と前後の空白を落とす）。
            // 混ざると同じお客様が別々に数えられ、リピート判定と履歴が分かれるため。
            'client' => \App\Support\ClientName::normalize($request->input('client')),
            'start_date' => $request->input('start_date'),
            'start_time' => $request->input('start_time'),
            'end_time' => $request->input('end_time'),
            'event_enter_time' => $request->input('event_enter_time'),
            'event_start_time' => $request->input('event_start_time'),
            'event_end_time' => $request->input('event_end_time'),
            // イベント時間（入場・開始・終了）がまだ決まっていない＝画面に「本番時間未定」と出す
            'event_time_tbd' => $request->has('event_time_tbd'),
            'location' => $request->input('location'),
            'is_outdoor' => $request->filled('outdoor') ? ($request->input('outdoor') === '屋外') : null,
            'lodging' => $request->input('lodging'),
            // イベント数として数えるか（先-2）。auto＝null／yes＝true／no＝false。
            // 数え方の正本は App\Support\EventCount（自動のルールもそこに置く）。
            'count_as_event' => match ((string) $request->input('count_as_event', 'auto')) {
                'yes'   => true,
                'no'    => false,
                default => null,   // auto（未指定も自動あつかい）
            },
            'assembly_type' => $request->input('assembly_type'),
            // ⚠ 集合場所の詳細（assembly_detail）と、スタッフ本人に伝えること
            //   （staff_belongings / staff_dresscode / staff_notes）はここでは触らない。
            //   入力する場所をスタッフ公開ボードの「📣 スタッフに伝えること」1か所にまとめたため
            //   （2026-08-21 baba）。ここで拾うと、案件を保存し直すたびに空で上書きしてしまう。
            'staff_role' => $request->input('staff_role'),
            // 運営人数は「6〜8人」のように範囲で入れられる（2026-08-25 baba）。
            // ⚠ 計算に使う数字（required_count）は範囲の**多いほう**。
            //   こうすると「残り○名」の計算をひとつも変えずに「8人埋まって初めて満員」になる。
            'required_count' => Headcount::parse($request->input('required_count'))['max'],
            'required_count_min' => Headcount::parse($request->input('required_count'))['min'],
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

        // 「同じ内容で次の日程を追加」＝保存したあと、同じ内容を入れた新規フォームをもう一度開く。
        // 中身は複製（?copy=）と同じ仕組みで、開催日と運営シートURLだけ空になる
        // （これまでは案件一覧に戻ってしまい、続けて登録できなかった・2026-08-21 baba）。
        if ($intent === 'next') {
            return redirect('/project-form?copy=' . urlencode($id))
                ->with('status', $message . ' 続けて次の日程を登録できます（開催日を入れてください）。');
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
     * 案件一覧の詳細セル（D／SD／物品担当／移動／音響）を保存する（POST /projects/cells）。
     * ケータリングと同じ「1項目だけ更新」の考え方。1セルずつ変える運用なので、
     * 送られてきたキーだけ書き換える（送っていない項目は消さない）。空文字は null（担当なし）に倒す。
     * 担当（director_id/sd_id/goods_owner_id）は社員（people の employee）に実在するIDだけ受け付け、
     * それ以外（空・不正なID）はすべて null＝担当なしにする。
     */
    public function saveCells(Request $request)
    {
        $request->validate([
            'id'             => ['required', 'string', 'exists:projects,id'],
            'director_id'    => ['nullable', 'string'],
            'sd_id'          => ['nullable', 'string'],
            'goods_owner_id' => ['nullable', 'string'],
            // 複数選べるようにしたので、つないだ文字（電車+IKUSAカー+...）が入る（2026-08-25 baba）。
            'transport'      => ['nullable', 'string', 'max:200'],
            'audio_equipment' => ['nullable', 'string', 'max:200'],
            // 備考（社員だけが見るメモ）。アサイン系の画面からもその場で直せるようにした（2026-08-21 baba）。
            'note'            => ['nullable', 'string', 'max:2000'],
            // アサイン状況。日別ボードの「✓ 確定にする」から保存する（2026-08-21 baba）。
            // 下書き・キャンセルはここでは扱わない（案件登録・削除の流れで決まるものなので混ぜない）。
            'status'          => ['nullable', 'in:未着手,調整中,確定,完了'],
            // 制作・記録（案件一覧の詳細でその場保存）
            'pub_logo'       => ['nullable', 'string', 'max:20'],
            'pub_camera'     => ['nullable', 'string', 'max:20'],
            'pub_article'    => ['nullable', 'string', 'max:20'],
            'pub_video'      => ['nullable', 'string', 'max:20'],
            // 準備チェック（案件一覧の詳細で付け外し・その場保存）
            'prep_line_created'      => ['nullable', 'boolean'],
            'prep_line_sent'         => ['nullable', 'boolean'],
            'prep_line_double_check' => ['nullable', 'boolean'],
            'prep_handover'          => ['nullable', 'boolean'],
            'prep_script'            => ['nullable', 'boolean'],
        ]);

        $project = Project::findOrFail($request->input('id'));
        // 拠点チェック（保存の入口で必ず通す）＝他拠点の案件をURL直打ちで書き換えられないようにする。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }

        // D／SD＝アサインの役割なので、保存先は assignments に一本化する（2026-08-05 baba確定）。
        // 古い列（director_id / sd_id）にも同じ値を「写し」で書く＝表示がまだ古い列を読む画面が壊れないため。
        // 中身は App\Support\DirectorSync が担当（D決め画面と同じルールを1か所にまとめている）。
        if ($request->has('director_id') || $request->has('sd_id')) {
            DirectorSync::apply(
                $project,
                $request->input('director_id'),
                $request->input('sd_id'),
                $request->has('director_id'),   // 送られたキーだけ触る（セル1つずつ変える運用のため）
                $request->has('sd_id'),
            );
        }

        // 物品担当はアサインの役割ではないので projects のまま（baba確定）。
        // 実在の社員IDのみ採用し、空・不正は null（担当なし）。
        if ($request->has('goods_owner_id')) {
            $val = trim((string) $request->input('goods_owner_id'));
            $project->goods_owner_id = ($val !== '' && Person::employees()->whereKey($val)->exists())
                ? $val
                : null;
        }

        // 移動・音響：送られたキーだけ更新。空文字は null（未設定）に。
        foreach (['transport', 'audio_equipment', 'pub_logo', 'pub_camera', 'pub_article', 'pub_video'] as $key) {
            if ($request->has($key)) {
                $val = trim((string) $request->input($key));
                $project->{$key} = $val !== '' ? $val : null;
            }
        }

        // 備考。アサイン系の画面（日別ボード・案件別アサイン・ピックアップ）からもここへ保存する。
        // 保存の入口をこの1か所に寄せている＝拠点チェックと編集履歴（ProjectHistoryRecorder）が自動で効く。
        // 改行はそのまま残す（前後の空白だけ落とす）。空にしたら null＝未記入に戻す。
        if ($request->has('note')) {
            $val = trim((string) $request->input('note'));
            $project->note = $val !== '' ? $val : null;
        }

        // アサイン状況（未着手／調整中／確定／完了）。日別ボードの「✓ 確定にする」がここへ来る。
        if ($request->filled('status')) {
            $project->status = $request->input('status');
        }

        // 準備チェック（LINE作成／LINE概要送付／LINEダブチェ／引き継ぎ／台本）。
        // 送られたキーだけ更新＝チェック1つ押すたびに、その1つだけを保存する（2026-08-21 baba）。
        foreach (['prep_line_created', 'prep_line_sent', 'prep_line_double_check', 'prep_handover', 'prep_script'] as $key) {
            if ($request->has($key)) {
                $project->{$key} = $request->boolean($key);
            }
        }

        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * 手動アーカイブ（隠す／戻す）を保存する（POST /projects/archive）。
     * is_archived＝true でアーカイブ（隠す）、false で戻す（表示に復帰）。
     * これを保存すると、開催日による自動判定より手動が優先される（index の実効アーカイブ判定を参照）。
     */
    public function setArchive(Request $request)
    {
        $request->validate([
            'id'       => ['required', 'string', 'exists:projects,id'],
            'archived' => ['required', 'boolean'],
        ]);

        $project = Project::findOrFail($request->input('id'));
        $project->is_archived = $request->boolean('archived');
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * キャンセルの切り替え（POST /projects/cancel）。2026-08-26 baba要望。
     *
     * ・記録は消さない（削除とは別）。実施形態・状態・アサインもそのまま残る。
     * ・キャンセルにすると、イベント数に数えなくなり（App\Support\EventCount）、
     * これからの仕事を並べる画面（アサイン表・D決め・公開ボード・日別ボード・
     * スタッフ画面）から外れる。
     * ⚠ 拠点チェックを通す（他拠点の案件を勝手に中止にできないように）。
     */
    public function setCancelled(Request $request)
    {
        $request->validate([
            'id'        => ['required', 'string', 'exists:projects,id'],
            'cancelled' => ['required', 'boolean'],
        ]);

        $project = Project::findOrFail($request->input('id'));
        if (! ProjectAccess::canEdit($project)) {
            return response()->json(['ok' => false, 'message' => 'この案件は他の拠点のものなので変えられません。'], 403);
        }

        $project->is_cancelled = $request->boolean('cancelled');
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

        // CSV を行配列にする（BOM除去・文字コードをUTF-8にそろえる・CRLF対応）。
        // ExcelがShift_JISで保存したCSVもここで読めるようにしている（App\Support\CsvText）。
        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        $raw = CsvText::toUtf8($raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        if (count($lines) < 2) {
            return redirect('/project-import')
                ->with('import_error', 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        // 見出し行 → 列名→位置 の対応表。
        // ECSのテンプレートの列名だけでなく、**アサイン表（東京アサイン表）の列名でも読める**
        // （日程→開催日／コンテンツ→案件名／顧客名(代理店名)→クライアント 等）。
        // 読み替えの表は App\Support\ProjectImportColumns。2026-08-06 baba要望＝
        // 「アサイン表をCSVにして、列を並べ替えずにそのまま取り込みたい」。
        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $resolved = ProjectImportColumns::resolve($header);
        $col = $resolved['map'];
        $unmappedColumns = $resolved['unmapped'];   // 取り込まなかった見出し（あとで画面に出す）

        $get = function (array $row, string $name) use ($col) {
            $i = $col[$name] ?? null;
            $value = ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
            if ($value === '') {
                return '';
            }
            // 時刻の列は「8:00」に直す（Excelのシリアル値 0.3333… でも読めるように）。
            if (in_array($name, ProjectImportColumns::TIME_COLUMNS, true)) {
                return ProjectImportColumns::normalizeTime($value) ?: $value;
            }

            return $value;
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
            // 開催日は「2026/7/20」「2026年7月20日」「Excelの日付（数字）」でも読めるように直す。
            // 年が入っていない「7/20」は勘で補わない＝エラーにして人に直してもらう。
            $rawDate = $get($row, '開催日');
            $date = ProjectImportColumns::normalizeDate($rawDate);
            $count = $get($row, '運営人数');

            // --- 必須3項目のチェック（JSの validate と同じ基準）---
            $rowErrors = [];
            if ($name === '') {
                $rowErrors[] = '案件名が空です';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->isRealDate($date)) {
                $rowErrors[] = $rawDate !== ''
                    ? "開催日が読めません（{$rawDate}）。年から入れてください（例 2026-07-20）"
                    : '開催日が空です（例 2026-07-20）';
            }
            // 「6〜8」のような範囲も受ける（2026-08-25 baba）。読み方は Headcount が正本。
            // ⚠ ctype_digit だと「6〜8」を弾いてしまう。
            if (($count === '') || (Headcount::parse($count)['max'] ?? 0) < 1) {
                $rowErrors[] = '運営人数が空または不正です（例 16 ／ 6〜8）';
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
                'client' => \App\Support\ClientName::normalize($get($row, 'クライアント')),
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
                'required_count' => Headcount::parse((string) $count)['max'],
                'required_count_min' => Headcount::parse((string) $count)['min'],
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
                // ⚠ ディレクター列は取り込まない（2026-08-05 baba確定＝取込の時点ではDが決まっていないため）。
                //    Dは「D決め画面」または案件一覧のプルダウンで決める＝保存先は assignments に一本化。
                // 物品担当は名前 → 名簿(people)から一致を探してID化（無ければ空欄）。アサインの役割ではないので projects のまま。
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
        // どの列を無視したかを必ず知らせる（アサイン表のCSVをそのまま入れたときに
        // 「入ったつもりで入っていない項目」に気づけるようにするため）。
        if ($unmappedColumns) {
            $msg .= ' ※ 取り込まなかった列：' . implode('・', $unmappedColumns)
                . '（ECSに対応する項目がありません。必要なら教えてください）';
        }

        return redirect('/projects')->with('status', $msg);
    }

    /**
     * コンテンツ名の一覧 → content_ids（配列）。
     * マスタに無ければ CT-### を発番して新規作成する。store()/import() で共用。
     *
     * $oneOffNames＝「この案件だけで使う」と選ばれた名前。台帳には作らず、IDも持たない
     * （名前は projects.content_names に残るので、案件からは見える）。
     * CSV一括取込は選ぶ画面が無いため渡さない＝今までどおり台帳に登録する。
     */
    private function resolveContentIds(Collection $names, ?Collection $oneOffNames = null): array
    {
        $oneOffNames = $oneOffNames ?: collect();

        return $names->map(function ($name) use ($oneOffNames) {
            $existing = Content::where('content_name', $name)->first();
            if ($existing) {
                return $existing->id;
            }
            // 台帳に無い名前のうち「この案件だけで使う」ものは、台帳に作らない。
            if ($oneOffNames->contains($name)) {
                return null;
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
        })->filter()->values()->all();   // 単発コンテンツ（null）は content_ids に入れない
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

