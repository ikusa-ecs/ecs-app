<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Headcount;
use App\Support\OfficeScope;
use App\Support\OfficeSettings;
use App\Support\ProjectAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * スタッフ公開ボード（/assign-publish）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）を読み、公開ON/OFFは
 * ブラウザの localStorage に保存していた（＝閉じると消える・他PCで共有されない）。
 * ここでは DB の projects テーブルを読み、公開状態 staff_published を「背骨」として
 * 表示し、ボタン操作で DB に保存する。表示JS（表の組み立て）はそのまま流用する。
 *
 * ※ G-2 が触る ProjectController とは別物。ここからは projects の staff_published 列
 *   だけを更新し、他の列は触らない。
 */
class AssignPublishController extends Controller
{
    /** 公開ボードを表示（DBの案件＋公開状態を渡す）。 */
    public function index(Request $request)
    {
        $today = Carbon::today();

        // 拠点の表示範囲。この画面は**必ず1拠点ずつ**（2026-08-21 baba）。
        // 理由＝全拠点をまとめて表示すると、一括公開で他拠点の未公開案件まで
        // スタッフに出てしまう事故が起きるため。管理者以上はスイッチで拠点を選べるが、
        // 「全拠点」は選べない。一般社員はこれまでどおり自拠点固定。
        $office = OfficeScope::filterSingle($request);

        $cases = OfficeScope::applyToProjects(Project::query(), $office)
            ->notCancelled()   // キャンセルになった案件は公開の対象にしない（2026-08-26）
            ->orderBy('start_date')->get()->map(function (Project $p) use ($today) {
            // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。画面が日付・月分けに使う。
            $off = $p->start_date
                ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                : 0;

            // added ＝ 今日から登録日まで何日（マイナス＝過去に登録）。「登録〇/〇」表示用。
            $added = $p->created_at
                ? intdiv($p->created_at->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                : 0;

            return [
                'id'        => $p->id,
                'name'      => $p->project_name,
                'client'    => $p->client ?? '',
                'cat'       => $p->site_category ?? '通常',   // 現場種別（バッジ用）
                'category'  => $p->category ?? '',            // 案件区分（通常案件/追加案件）
                // 「6〜8」のような範囲もそのまま出す（2026-08-25 baba）。
                'need'      => Headcount::label($p->required_count_min, $p->required_count) ?: '—',
                'off'       => $off,
                'added'     => $added,
                'meet'      => $p->start_time ?? '—',          // 社員の集合時間
                'leave'     => $p->end_time ?? '—',            // 社員の解散時間
                'staffMeet' => $p->staff_meet_time,            // スタッフ向け集合（未設定=null→社員と同じ）
                'staffLeave' => $p->staff_leave_time,          // スタッフ向け解散（未設定=null→社員と同じ）
                'place'     => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'published' => (bool) $p->staff_published,      // 公開状態（DBの背骨）
                // 備考＝案件登録と同じ欄（projects.note）。2026-08-21 に担当メモと1つにまとめた。
                'memo'      => $p->note ?? '',
                // スタッフ本人に伝えること（本人の確定アサインにそのまま出る）
                'staffNotes' => $p->staff_notes ?? '',   // スタッフに伝えること（1欄・備考のような自由記入）
            ];
        })->values();

        return view('assign_publish', [
            'cases'  => $cases,
            // お知らせ文・締切日は「今見ている拠点」のもの（2026-08-25 baba要望）。
            // ⚠ 以前は全国共通だったため、東京で直すと東北のスタッフ画面まで変わっていた。
            'notice' => OfficeSettings::get(OfficeSettings::NOTICE, $office),
            'entryDeadline' => OfficeSettings::get(OfficeSettings::DEADLINE, $office),
            'officeScope' => $office,                       // 今絞っている拠点（null＝全拠点）。注記に使う
        ]);
    }

    /**
     * 公開ON/OFF を DB に保存する（1件でも複数まとめてでも同じ口）。
     * 受け取り：ids（案件IDの配列）／publish（true=公開・false=非公開）。
     */
    public function setPublish(Request $request)
    {
        $data = $request->validate([
            'ids'     => ['required', 'array', 'min:1'],
            'ids.*'   => ['string'],
            'publish' => ['required', 'boolean'],
            // 画面が見ていた拠点。違う拠点の案件は触らない＝取り違えの保険（2026-08-21 baba）。
            'office'  => ['nullable', 'string'],
        ]);

        $scope = trim((string) ($data['office'] ?? ''));

        // ⚠ ここを Project::whereIn(...)->update(...) の一括更新で書くと、
        //   モデルの保存イベントが動かないため公開ON/OFFだけ編集履歴に残らない
        //   （時間・メモ・区分は $project->save() なので残っていた）。
        //   公開は「スタッフに見えるかどうか」を切り替える一番大事な操作なので、
        //   1件ずつ save() して ProjectHistoryRecorder に必ず記録させる。
        $updated = 0;
        foreach (Project::whereIn('id', $data['ids'])->get() as $project) {
            if (! ProjectAccess::canEdit($project)) {
                continue;   // 他拠点の案件は公開状態を変えられない
            }
            if ($scope !== '' && (string) ($project->office ?? '') !== $scope) {
                continue;   // 画面で見ていた拠点と違う＝まとめて公開する事故を防ぐ
            }
            if ((bool) $project->staff_published === (bool) $data['publish']) {
                continue;   // すでにその状態＝履歴を汚さない
            }
            $project->staff_published = $data['publish'];
            $project->save();
            $updated++;
        }

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /**
     * スタッフ向けの集合・解散時間を DB に保存する。
     * 受け取り：id（案件ID）＋ staff_meet または staff_leave のどちらか／両方。
     * 空文字は「未設定」に戻す（null）＝社員の時間をそのまま使う扱いに戻る。
     */
    public function setTime(Request $request)
    {
        $data = $request->validate([
            'id'          => ['required', 'string'],
            'staff_meet'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'staff_leave' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $project = Project::findOrFail($data['id']);
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        if ($request->has('staff_meet')) {
            $project->staff_meet_time = trim((string) $request->input('staff_meet')) ?: null;
        }
        if ($request->has('staff_leave')) {
            $project->staff_leave_time = trim((string) $request->input('staff_leave')) ?: null;
        }
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * スタッフ画面のお知らせ文を DB に保存する（空＝既定文に戻す）。
     * 保存先は**拠点ごと**。どの拠点かは画面が送ってくる office で決める。
     */
    public function setNotice(Request $request)
    {
        $data = $request->validate([
            'notice' => ['nullable', 'string', 'max:2000'],
            'office' => ['nullable', 'string'],
        ]);

        $office = $this->editableOffice($request, $data['office'] ?? '');
        if ($office === null) {
            return response()->json(['ok' => false, 'message' => '他の拠点の設定は変えられません。'], 403);
        }

        OfficeSettings::put(OfficeSettings::NOTICE, $office, (string) ($data['notice'] ?? ''));

        return response()->json(['ok' => true, 'office' => $office]);
    }

    /**
     * 「この拠点の設定を変えてよいか」を確かめて、保存先の拠点名を返す（だめなら null）。
     *
     * ⚠ 画面から送られてきた拠点名をそのまま信じない。
     *   一般社員が他拠点のお知らせ文を書き換えられてしまわないようにするため
     *   （公開ボードは拠点を1つに絞って使う画面・2026-08-21 の決まりと同じ考え方）。
     */
    private function editableOffice(Request $request, string $sent): ?string
    {
        $sent = trim($sent);
        $mine = OfficeScope::filterSingle($request);

        if ($sent === '' || $sent === $mine) {
            return $mine;
        }

        // 管理者以上は拠点スイッチで他拠点を見られるので、実在する拠点なら保存してよい。
        if (OfficeScope::canSeeAll() && in_array($sent, OfficeScope::options(), true)) {
            return $sent;
        }

        return null;
    }

    /**
     * 案件ごとの「💬備考」を DB に保存する。
     *
     * 2026-08-21 baba：案件登録の備考と公開ボードの担当メモを1つにまとめた。
     * 保存先は projects.note＝案件登録・案件一覧と同じ欄なので、どこで書いても同じ備考になる。
     * （旧 publish_memo には書かない。既存の中身はマイグレーションで note に足してある）
     * 空文字は「未入力」に戻す（null）。受け取り：id（案件ID）＋ memo（本文／空可）。
     */
    public function setMemo(Request $request)
    {
        $data = $request->validate([
            'id'   => ['required', 'string', 'exists:projects,id'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);

        $project = Project::findOrFail($data['id']);
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        $project->note = trim((string) ($data['memo'] ?? '')) ?: null;
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * 必要人数（運営人数）を保存する（2026-08-21 baba）。
     *
     * 募集をかける直前に人数を直したい場面が多いので、公開ボードからも変えられるようにした。
     * 保存先は projects.required_count＝案件登録・アサイン表と同じ列（食い違いが起きない）。
     * 空で送れば「未定」に戻る（スタッフ画面では既定5名として見せる）。
     */
    public function setCount(Request $request)
    {
        $data = $request->validate([
            'id'    => ['required', 'string', 'exists:projects,id'],
            'count' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $project = Project::findOrFail($data['id']);
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        $project->required_count = $data['count'] !== null && $data['count'] !== ''
            ? (int) $data['count']
            : null;
        $project->save();

        return response()->json(['ok' => true, 'count' => $project->required_count]);
    }

    /**
     * スタッフ本人に伝えること（備考のように自由に書く1欄）を保存する。
     *
     * 2026-08-21 baba：集合場所の詳細／服装／持ち物／注意事項の4欄をやめ、1欄にまとめた
     * （実際は備考のように書きたいだけで、欄が分かれていると入力が面倒だったため）。
     * 保存先は projects.staff_notes なので編集履歴にも残る。
     * 受け取り：id（案件ID）＋ notes（空可）。
     */
    public function setStaffInfo(Request $request)
    {
        $data = $request->validate([
            'id'    => ['required', 'string', 'exists:projects,id'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $project = Project::findOrFail($data['id']);
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        $project->staff_notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * 案件を「追加案件」にする／戻す（バッジの手動オン/オフ）。
     * 受け取り：id（案件ID）＋ is_extra（true=追加案件・false=通常案件）。
     * 追加にした時は「公開した日」extra_published_at を今日で記録（締切=公開日+3日の起点）。
     * 通常に戻した時は extra_published_at を空（null）に戻す。
     */
    public function setCategory(Request $request)
    {
        $data = $request->validate([
            'id'       => ['required', 'string'],
            'is_extra' => ['required', 'boolean'],
        ]);

        $project = Project::findOrFail($data['id']);
        // 拠点チェック（他拠点の案件をURL直打ちで書き換えられないようにする）。
        if ($deny = ProjectAccess::denyJson($project)) {
            return $deny;
        }
        if ($data['is_extra']) {
            $project->category = '追加案件';
            // すでに公開日があればそのまま（付け直しで締切がずれないように）、無ければ今日を記録。
            if (empty($project->extra_published_at)) {
                $project->extra_published_at = Carbon::today();
            }
        } else {
            $project->category = '通常案件';
            $project->extra_published_at = null;
        }
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * 追加案件バッジを「まとめて」オン/オフする（チェックした複数案件が対象）。
     * 受け取り：ids（案件IDの配列）＋ is_extra（true=追加案件・false=通常案件）。
     * 1件版 setCategory と同じルール（追加ONで公開日を今日記録・OFFで null に戻す）。
     */
    public function setCategoryBulk(Request $request)
    {
        $data = $request->validate([
            'ids'      => ['required', 'array', 'min:1'],
            'ids.*'    => ['string'],
            'is_extra' => ['required', 'boolean'],
        ]);

        // 他拠点の案件は触らない（拠点チェック）。
        $projects = Project::whereIn('id', $data['ids'])->get()
            ->filter(fn (Project $p) => ProjectAccess::canEdit($p));
        foreach ($projects as $project) {
            if ($data['is_extra']) {
                $project->category = '追加案件';
                if (empty($project->extra_published_at)) {
                    $project->extra_published_at = Carbon::today();
                }
            } else {
                $project->category = '通常案件';
                $project->extra_published_at = null;
            }
            $project->save();
        }

        return response()->json(['ok' => true, 'updated' => $projects->count()]);
    }

    /**
     * 通常案件の「一斉の締切日」を DB に保存する（**拠点ごとに1つ**・空＝未設定に戻す）。
     * 受け取り：date（YYYY-MM-DD もしくは空）＋ office（どの拠点の締切か）。
     */
    public function setDeadline(Request $request)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'office' => ['nullable', 'string'],
        ]);

        $office = $this->editableOffice($request, $data['office'] ?? '');
        if ($office === null) {
            return response()->json(['ok' => false, 'message' => '他の拠点の設定は変えられません。'], 403);
        }

        OfficeSettings::put(OfficeSettings::DEADLINE, $office, (string) ($data['date'] ?? ''));

        return response()->json(['ok' => true, 'office' => $office]);
    }
}
