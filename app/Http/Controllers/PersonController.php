<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Models\StaffRelation;
use App\Models\StaffRoleEligibility;
use App\Support\AssignmentRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 人名簿（people テーブル）の画面。社員・スタッフは同じ1テーブル（role で区別）。
 * これまで各画面が直書き／people.js を読んでいたのを、DB から読むように繋ぐ。
 */
class PersonController extends Controller
{
    /** 社員名簿（/employees）。 */
    public function employees()
    {
        $today = Carbon::today();

        // 画面側の区分コード（CSS・絞り込み用）← DB は日本語ラベルで保存している
        $deptCode = ['イベプラ' => 'plan', 'セールス' => 'sales', 'クリエイティブ' => 'creative'];

        // 画面（employees.blade.php）が読む形に詰め替える。表示JSはそのまま使う。
        $employees = Person::employees()
            ->orderBy('id')
            ->get()
            ->map(function (Person $p) use ($today, $deptCode) {
                // joinedMonths ＝ 入社からの経過月数（6以下で「新人」バッジが付く）
                $months = $p->hire_date
                    ? (int) floor($p->hire_date->copy()->startOfDay()->diffInMonths($today))
                    : 0;

                return [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'dept'         => $deptCode[$p->department] ?? 'plan',
                    'office'       => $p->office ?? '',   // 事務所（地域オフィス）
                    'joinedMonths' => $months,
                    'exp'          => $p->experienced_contents ?? [],
                    'dexp'         => $p->director_contents ?? [],
                    'wear'         => $p->shirt_size ?? '',
                    'shoe'         => $p->shoe_size ?? '',
                ];
            })
            ->values();

        // 経験コンテンツ編集のプルダウン候補＝コンテンツ台帳（有効なもの・名前順）。
        $contentOptions = Content::where('active', true)
            ->orderBy('content_name')
            ->pluck('content_name')
            ->filter()
            ->unique()
            ->values();

        return view('employees', [
            'employees'      => $employees,
            'contentOptions' => $contentOptions,
        ]);
    }

    /**
     * 社員名簿の詳細から「経験のあるコンテンツ／Dの経験のあるコンテンツ」を保存する
     * （POST /employees/experience）。ケータリング保存と同じ「その項目だけ更新」のやり方。
     * exp ＝経験コンテンツ名の配列／dexp ＝Dの経験コンテンツ名の配列。どちらも来た方だけ更新。
     */
    public function saveExperience(Request $request)
    {
        $data = $request->validate([
            'id'     => ['required', 'string'],
            'exp'    => ['sometimes', 'array'],
            'exp.*'  => ['string', 'max:100'],
            'dexp'   => ['sometimes', 'array'],
            'dexp.*' => ['string', 'max:100'],
        ]);

        $person = Person::employees()->findOrFail($data['id']);
        if ($request->has('exp')) {
            $person->experienced_contents = array_values(array_unique($data['exp'] ?? []));
        }
        if ($request->has('dexp')) {
            $person->director_contents = array_values(array_unique($data['dexp'] ?? []));
        }
        $person->save();

        return response()->json(['ok' => true]);
    }

    /** スタッフ名簿（/staff）。 */
    public function staff()
    {
        // ポジション可否・NGペアも一緒に読む（毎回引かないようにする）。
        $people = Person::staff()
            ->with(['roleEligibilities', 'ngRelations'])
            ->orderByDesc('experience_count')
            ->get()
            ->map(function (Person $p) {
                // できるポジション → people.js と同じ {D:true, OP:false, ...} の形に戻す
                $can = $p->roleEligibilities->pluck('position')->all();
                $pos = [];
                foreach (AssignmentRole::POSITIONS as $k) {
                    $pos[$k] = in_array($k, $can, true);
                }

                return [
                    'id'        => $p->id,
                    'role'      => 'staff',
                    'name'      => $p->name,
                    'office'    => $p->office ?? '',   // 事務所（地域オフィス）
                    // joinDate ＝ 区分（新人/中堅/ベテラン）計算の元。people.js と同じく文字列で渡す。
                    'joinDate'  => $p->hire_date?->format('Y-m-d'),
                    'exclusive' => (bool) $p->is_exclusive,
                    'total'     => $p->experience_count ?? 0,
                    'pos'       => $pos,
                    'ng'        => $p->ngRelations->pluck('partner_name')->all(),
                    'dnote'     => $p->planner_impression ?? '',
                    'traits'    => [
                        'follow'  => (bool) $p->can_follow_newbie,
                        'starter' => (bool) $p->self_starter,
                        'atmos'   => (bool) $p->improves_atmosphere,
                    ],
                ];
            })
            ->values();

        // 「稼働状況」タブぶんのデータ。計算は StaffStatusController に一本化して再利用する。
        $status = app(StaffStatusController::class)->buildStatus();

        return view('staff', ['people' => $people, 'status' => $status]);
    }

    /**
     * スタッフ編集の保存先（POST /staff/{id}/edit）。/staff の詳細パネルからAJAXで呼ばれる。
     * ポジション可否・NGペアは「この人の分を作り直す（全消し→入れ直し）」。専属・人柄・メモは people を更新。
     */
    public function staffUpdate(Request $request, string $id)
    {
        $person = Person::staff()->find($id);
        if (! $person) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => 'スタッフが見つかりませんでした。'], 404);
            }
            return redirect('/staff')->with('status', 'スタッフが見つかりませんでした。一覧から選び直してください。');
        }

        $data = $request->validate([
            'positions'           => ['sometimes', 'array'],
            'positions.*'         => ['string'],
            'managed_positions'   => ['sometimes', 'array'],   // このフォームが扱うポジションの範囲（未指定＝全件置換）
            'managed_positions.*' => ['string'],
            'ng'                  => ['nullable', 'string', 'max:2000'],
            'impression'          => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($person, $request, $data) {
            // 1) できるポジション：正規コードだけ受け付ける。
            //    managed_positions が来たら「その範囲だけ」入れ替える（範囲外の可否は温存）。来なければ全件置換。
            $submitted = array_values(array_unique(array_filter(
                $data['positions'] ?? [],
                fn ($p) => AssignmentRole::isValid($p)
            )));
            $managed = array_values(array_filter(
                $data['managed_positions'] ?? [],
                fn ($p) => AssignmentRole::isValid($p)
            ));

            $q = StaffRoleEligibility::where('staff_id', $person->id);
            if (! empty($managed)) {
                $q->whereIn('position', $managed);
                $submitted = array_values(array_intersect($submitted, $managed));
            }
            $q->delete();
            foreach ($submitted as $pos) {
                StaffRoleEligibility::create(['staff_id' => $person->id, 'position' => $pos]);
            }

            // 2) NGペア：改行区切りの氏名。登録済みスタッフなら people.id もひも付ける。この人の分を入れ直す。
            $names = collect(preg_split('/\r\n|\r|\n/', (string) ($data['ng'] ?? '')))
                ->map(fn ($s) => trim($s))
                ->filter()
                ->unique()
                ->values();
            StaffRelation::where('staff_id', $person->id)->delete();
            foreach ($names as $name) {
                StaffRelation::create([
                    'staff_id'      => $person->id,
                    'partner_name'  => $name,
                    'partner_id'    => Person::where('name', $name)->value('id'),
                    'relation_type' => 'NG',
                ]);
            }

            // 3) 専属・人柄・メモ（people の実在カラム）。
            $person->is_exclusive        = $request->boolean('exclusive');
            $person->can_follow_newbie   = $request->boolean('follow');
            $person->self_starter        = $request->boolean('starter');
            $person->improves_atmosphere = $request->boolean('atmos');
            $person->planner_impression  = $data['impression'] ?? null;
            $person->save();
        });

        $message = $person->name . ' さんの情報を保存しました。';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect('/staff')->with('status', $message);
    }
}
