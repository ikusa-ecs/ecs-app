<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Project;
use App\Models\ProjectFinance;
use App\Support\FinanceAccess;
use App\Support\FinanceItems;
use App\Support\Lodging;
use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * マイページ・収支入力（/mypage-finance）。
 *
 * これまで画面は cases.js（見本）＋Blade内ベタ書きの MY_ASSIGN で動いていた。
 * ここでは マイページと同じ共通部品（PersonalCases）で
 *   ・自分＝誰か   ・全案件（DB）   ・自分のアサイン（案件ID→役割コード）
 * を渡し、収支の対象案件を本物のデータで出す。
 *
 * 収支の対象＝自分が「D（ディレクター）」の案件、または「営業担当」の案件。
 *
 * 収支の入力（売上・経費明細・メモ）は project_finances テーブルへ本物保存する。
 * 画面を開き直すと、前回入力した値が復元される（ブラウザ記憶ではなく共有DBが正）。
 */
class MyPageFinanceController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();
        $me = PersonalCases::meModel();

        $cases = PersonalCases::cases($today);

        // 収支一覧（/finance-list）の「✏ 入力する」から ?case=<案件ID> で来たとき、
        // その案件を選べるようにする。画面の案件リストは通常「自分がD／営業担当の案件」だけなので、
        // 管理者が他の人の担当案件を直す場合は、ここで1件だけリストに加える。
        // ※ 加えてよいかはサーバー側で判定する（FinanceAccess::canEdit）＝権限の抜け道にしない。
        $forcedCase = null;
        $requested = (string) $request->query('case', '');
        if ($requested !== '') {
            $project = Project::find($requested);
            if ($project && FinanceAccess::canEdit($project, $me)) {
                $forcedCase = $project->id;
            }
        }

        // 対象案件ぶんの「保存済み収支」を案件ID→{売上・明細・メモ} の形で用意する。
        // 画面はこれを使って、案件を選んだときに前回入力を各欄へ復元する。
        $finances = ProjectFinance::whereIn('project_id', $cases->pluck('id'))
            ->get()
            ->keyBy('project_id')
            ->map(fn (ProjectFinance $f) => [
                'revenue' => $f->revenue,
                'items' => $f->items ?? [],
                'memo' => $f->memo ?? '',
            ]);

        return view('mypage_finance', [
            'me' => PersonalCases::meInfo($me),
            'cases' => $cases,
            'myAssign' => PersonalCases::myAssign($me),
            'finances' => $finances,
            'staffCounts' => self::staffCounts($cases->pluck('id')->all()),
            'headCounts' => self::headCounts($cases->pluck('id')->all()),
            'roster' => self::roster($cases->pluck('id')->all()),
            'stayNights' => self::stayNights($cases->pluck('lodging', 'id')->all()),
            // 経費の費目＝サーバー側の正本（App\Support\FinanceItems）から渡す。
            // 画面のJSに単価をベタ書きすると、一覧（/finance-list）の計算と食い違うため。
            'costItems' => FinanceItems::all(),
            'forcedCase' => $forcedCase,   // 一覧から来た案件（権限OKのときだけ入る。null＝通常）
        ]);
    }

    /**
     * 案件ID → アサインされている**スタッフ**の人数（社員とキャンセルは数えない）。
     * 当日スタッフ費の数量の「仮の数字」に使う（FBシート No.28・2026-11-12）。
     * 社員には当日スタッフ費が出ないので、運営人数そのままだと多く入ってしまっていた。
     * ⚠ アサインが1人もいない案件はキーを入れない＝画面は仮の数量を入れない（2026-11-13 小沼さん。それまでは運営人数を入れていた）。
     *
     * @param  list<string>  $projectIds
     * @return array<string, int>
     */
    public static function staffCounts(array $projectIds): array
    {
        $out = [];
        foreach (array_chunk($projectIds, 500) as $chunk) {
            $rows = Assignment::query()
                ->join('people', 'people.id', '=', 'assignments.staff_id')
                ->whereIn('assignments.project_id', $chunk)
                ->where('assignments.status', '!=', 'キャンセル')
                ->get(['assignments.project_id', 'people.role as person_role']);
            foreach ($rows as $r) {
                $out[$r->project_id] = ($out[$r->project_id] ?? 0) + ($r->person_role === 'staff' ? 1 : 0);
            }
        }

        return $out;
    }

    /**
     * 案件ID → アサインされている**全員**の人数（スタッフ＋社員。キャンセルは数えない）。
     * 飲食費・宿泊費の数量の「仮の数字」に使う（FBシート No.28 後半・2026-11-13 小沼さん決定＝社員も数える）。
     * ⚠ アサインが1人もいない案件はキーを入れない＝画面は仮の数量を入れない。
     *
     * @param  list<string>  $projectIds
     * @return array<string, int>
     */
    public static function headCounts(array $projectIds): array
    {
        $out = [];
        foreach (array_chunk($projectIds, 500) as $chunk) {
            $rows = Assignment::query()
                ->whereIn('project_id', $chunk)
                ->where('status', '!=', 'キャンセル')
                ->selectRaw('project_id, count(distinct staff_id) as n')
                ->groupBy('project_id')
                ->get();
            foreach ($rows as $r) {
                $out[$r->project_id] = (int) $r->n;
            }
        }

        return $out;
    }

    /**
     * 案件ID → その案件に入っている人の一覧（名前・役割・社員かどうか。キャンセルは出さない。社員が先）。
     * 収支入力の見出しに「当日の人数と、誰がいたか」を出す（2026-11-13 小沼さん「必要人数ではなく当日の人数と名前を。思い出せるから」）。
     * ⚠ 派遣は名簿に入らないので、ここには出ない。
     *
     * @param  list<string>  $projectIds
     * @return array<string, list<array{name:string, role:string, emp:bool}>>
     */
    public static function roster(array $projectIds): array
    {
        $out = [];
        foreach (array_chunk($projectIds, 500) as $chunk) {
            $rows = Assignment::query()
                ->join('people', 'people.id', '=', 'assignments.staff_id')
                ->whereIn('assignments.project_id', $chunk)
                ->where('assignments.status', '!=', 'キャンセル')
                ->orderBy('assignments.id')
                ->get(['assignments.project_id', 'assignments.staff_id', 'assignments.role', 'people.name', 'people.role as person_role']);
            foreach ($rows as $r) {
                // 同じ人が2日入っていても1回だけ出す。
                $out[$r->project_id][$r->staff_id] ??= ['name' => (string) $r->name, 'role' => (string) $r->role, 'emp' => $r->person_role !== 'staff'];
            }
        }

        return array_map(function (array $people) {
            $list = array_values($people);
            usort($list, fn ($a, $b) => ($b['emp'] <=> $a['emp']));   // 社員が先（同じ中では入れた順のまま）

            return $list;
        }, $out);
    }

    /**
     * 案件ID → 泊まる回数（前泊あり＝1・後泊あり＝1・両方＝2）。0 の案件はキーを入れない。
     * 宿泊費の数量（泊）の仮の数字＝人数×これ。⚠ 誰が泊まるかの記録は無いので、全員が泊まるとして数える（画面で直せる）。
     * 前泊・後泊の言葉の正本＝Lodging。
     *
     * @param  array<string, ?string>  $lodgingById
     * @return array<string, int>
     */
    public static function stayNights(array $lodgingById): array
    {
        $out = [];
        foreach ($lodgingById as $id => $lodging) {
            $n = (Lodging::hasPreStay($lodging) ? 1 : 0) + (Lodging::hasPostStay($lodging) ? 1 : 0);
            if ($n > 0) {
                $out[$id] = $n;
            }
        }

        return $out;
    }

    /**
     * 収支（売上・経費明細・メモ）を project_finances へ保存する。
     * 画面から JSON（AJAX）で呼ばれる想定。成功時は {ok:true} を返す。
     * 案件1件＝1行（project_id が同じなら上書き）。
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', Rule::exists('projects', 'id')],
            'revenue' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);

        // ⚠ 直せるのは「その案件の担当者本人（D／営業担当）」と「管理者以上」だけ（2026-08-06 baba確定）。
        //   以前はこのチェックが無く、社員なら他人の担当案件の収支も案件IDを直接送れば書き換えられた。
        $project = Project::find($data['project_id']);
        $me = PersonalCases::meModel();
        if (! $project || ! FinanceAccess::canEdit($project, $me)) {
            return response()->json([
                'ok' => false,
                'message' => 'この案件の収支を保存できる権限がありません（担当のディレクター・営業担当、または管理者のみ）。',
            ], 403);
        }

        ProjectFinance::updateOrCreate(
            ['project_id' => $data['project_id']],
            [
                'revenue' => $data['revenue'] ?? null,
                'items' => $this->cleanItems($request->input('items', [])),
                'memo' => $data['memo'] ?? null,
                'updated_by' => $me->id ?? null,   // 誰が入れたか（一覧で「最後に入力した人」を出す）
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * 経費明細を安全な形に整える。
     *   形＝ {費目キー: {qty: 数量, amount: 金額}} の連想配列。
     *   ・数値以外は無視、負数は 0 にクリップ（円・数量はいずれも整数で扱う）。
     *   ・何も入っていない（すべて 0/空）の費目は保存しない。
     */
    private function cleanItems($items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $key => $row) {
            if (! is_string($key) || ! is_array($row)) {
                continue;
            }

            $entry = [];
            foreach (['qty', 'amount'] as $field) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }
                $value = $row[$field];
                if (! is_numeric($value)) {
                    continue; // 数値以外は無視
                }
                $value = (int) $value;
                if ($value < 0) {
                    $value = 0; // 負数はクリップ
                }
                $entry[$field] = $value;
            }

            // 空（数量も金額も 0）の費目は保存しない
            $hasValue = false;
            foreach ($entry as $value) {
                if ($value > 0) {
                    $hasValue = true;
                }
            }
            if ($entry && $hasValue) {
                $clean[$key] = $entry;
            }
        }

        return $clean;
    }
}
