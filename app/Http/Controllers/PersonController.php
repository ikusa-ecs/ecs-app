<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Models\StaffRelation;
use App\Models\StaffRoleEligibility;
use App\Support\AssignmentRole;
use App\Support\Departments;
use App\Support\ExperienceCount;
use App\Support\LoginInvite;
use App\Support\OfficeScope;
use App\Support\SpotStaff;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        // 所属の色コード・表示名の正本は App\Support\Departments（所属が増えたらそこだけ直す）。
        // ⚠ 以前はここに3つだけ直書きし、既定を 'plan' にしていたため、
        //   経営管理・ARENA などの人が「イベプラ」と誤表示されていた（2026-08-24 修正）。

        // 画面（employees.blade.php）が読む形に詰め替える。表示JSはそのまま使う。
        // 並びは社歴順（入社日の古い人が上）。入社日が未入力の人は末尾。
        $employees = Person::employees()
            ->bySeniority()
            ->get()
            ->map(function (Person $p) use ($today) {
                // joinedMonths ＝ 入社からの経過月数（6以下で「新人」バッジが付く）
                $months = $p->hire_date
                    ? (int) floor($p->hire_date->copy()->startOfDay()->diffInMonths($today))
                    : 0;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'kana' => $p->name_kana ?? '',   // ふりがな（五十音順の並び・未入力の人を見つける用）
                    'dept' => Departments::code($p->department),   // 主な所属の色コード（絞り込みにも使う）
                    'deptName' => Departments::label($p->department),  // 画面に出す文字（未設定は「未設定」）
                    // 兼務を含めた所属すべて（先頭＝主な所属）。バッジを複数出すのに使う。
                    'depts' => collect($p->departmentList())
                        ->map(fn ($d) => ['name' => $d, 'code' => Departments::code($d)])
                        ->values(),
                    'cwid' => $p->chatwork_id ?? '',   // チャットワークID（未登録を見つける用）
                    'active' => (bool) $p->active,      // 在籍中か（false＝退職）
                    'email' => $p->email ?? '',
                    // ログインの状態（none=アカウント無し／temp=仮パスワード発行済み／
                    // invited=案内メール送信済み／ready=本人が設定済み）
                    'login' => $p->password_set_at ? 'ready' : ($p->invited_at ? 'invited' : ($p->password ? 'temp' : 'none')),
                    'invitedAt' => optional($p->invited_at)->format('Y-m-d'),
                    'deptMain' => $p->department ?? '',   // 主な所属（編集欄の初期値）
                    'office' => $p->office ?? '',   // 事務所（地域オフィス）
                    'joinedMonths' => $months,
                    // 入社年月日と、そこから計算した区分（2026-08-28 baba要望）。
                    // ⚠ 区分は保存していない＝入社日から毎回計算する。だから入社日が間違っていると
                    //   新入社員がベテランに見える。原因が分かるよう、両方を並べて出す。
                    'hireDate' => $p->hire_date?->format('Y-m-d') ?? '',
                    'level' => $p->skill_level ?? '',
                    'exp' => $p->experienced_contents ?? [],
                    'dexp' => $p->director_contents ?? [],
                    'wear' => $p->shirt_size ?? '',
                    'shoe' => $p->shoe_size ?? '',
                    // サイズ編集パネルの初期値（今の登録値をそのまま表示・空欄なら空文字）。
                    'height' => $p->height ?? '',
                    'shoeSize' => $p->shoe_size ?? '',
                    'shirtSize' => $p->shirt_size ?? '',
                    // アサインの候補に出すか（2026-08-26 baba要望）。false＝出勤可能日の一覧・
                    // D決め・D/SD/物品担当のプルダウンに出さない。名簿には今までどおり出る。
                    'inAssignPool' => (bool) $p->in_assign_pool,
                ];
            })
            ->values();

        // 経験コンテンツ編集のプルダウン候補＝コンテンツ台帳（有効なもの）。
        // 並びはマスタ管理の台帳と同じ（並び順→ID順）。案件登録の候補とも同じ順にそろえる
        // ＝画面によって並びが違うと探しにくいため（baba要望 2026-08-24）。
        $contentOptions = Content::where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('content_name')
            ->filter()
            ->unique()
            ->values();

        return view('employees', [
            // 拠点で絞って見るための選択肢（2026-08-25 baba要望）。既定は自分の拠点。
            'offices' => OfficeScope::options(),
            'myOffice' => OfficeScope::filterSingle(request()),
            // 「アサイン表に出す／出さない」を切り替えられるか＝管理者以上（アサイン担当）。
            'canManageAssignPool' => in_array(optional(Auth::user())->permission, ['manager', 'admin'], true),
            'employees' => $employees,
            'contentOptions' => $contentOptions,
            // 経験回数（自動集計・2026-08-27）。社員も現場に出るので同じように出す。
            'experience' => ExperienceCount::forMany($employees->pluck('id')->all()),
            // 「退職にする」「削除」を出すか＝Administrator だけ（権限4段階の決まり）。
            'canManagePeople' => optional(Auth::user())->permission === 'admin',
            // 拠点（事務所）を直せるか＝管理者以上（2026-08-27 baba選択）。
            // ⚠ canManagePeople（氏名・所属＝Administratorのみ）とは別の線引きなので分けて渡す。
            'canManageOffice' => in_array(optional(Auth::user())->permission, ['manager', 'admin'], true),
            // ログイン案内メールを送れるか＝管理者以上（アカウント発行と同じ扱い）。
            'canInvite' => in_array(optional(Auth::user())->permission, ['manager', 'admin'], true),
            // 自分自身には出さない（自分を消す・退職にするのは止めている）。
            'myId' => optional(Auth::user())->id,
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
            'id' => ['required', 'string'],
            'exp' => ['sometimes', 'array'],
            'exp.*' => ['string', 'max:100'],
            'dexp' => ['sometimes', 'array'],
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

    /**
     * 社員の情報（氏名・ふりがな・所属・サイズ）を保存する（POST /employees/{id}/profile）。
     * 社員名簿の詳細パネルからAJAXで呼ばれる。来た項目だけを people に上書きする。
     * ※ 社員の新規作成はここでは行わない（アカウント発行は /account-new に集約）。
     *
     * 氏名・ふりがな・所属を直せるようにした理由（2026-08-24 baba要望）：
     * 名簿CSVの見本行（山田花子／やまだ はなこ）を消し忘れて取り込んでしまい、
     * 本人以外は直せない状態になった（本人がログインするまで間違ったふりがなが残る）。
     * ⚠ 他人の氏名・ふりがな・所属を書き換えるのは Administrator だけ。
     *   ルートではなくここで項目ごとに見る（ルートに tier:admin を付けると、
     *   今まで管理者以上ができていたサイズ編集まで止まってしまうため）。
     */
    public function saveEmployeeProfile(Request $request, string $id)
    {
        // 対象は「社員」に限定（people の中で role=employee の行だけ）。
        $person = Person::employees()->find($id);
        if (! $person) {
            return response()->json(['ok' => false, 'message' => '社員が見つかりませんでした。'], 404);
        }

        $data = $request->validate([
            'height' => ['nullable', 'string', 'max:20'],  // 身長（cm）
            'shoe_size' => ['nullable', 'string', 'max:20'],  // 靴のサイズ
            'shirt_size' => ['nullable', 'string', 'max:20'],  // 服のサイズ
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'name_kana' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:50'],
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'max:50'],
            // アサインの候補に出すか（'1'/'0'）。
            'in_assign_pool' => ['nullable', 'boolean'],
            // 拠点（事務所）。2026-08-27 baba要望＝これまで画面から直せなかった。
            'office' => ['nullable', 'string', 'max:50'],
            // 入社年月日。2026-08-28 baba要望＝これまで画面のどこにも出ておらず、
            // 「新入社員がベテランになっている」の原因を確認も修正もできなかった。
            'hire_date' => ['nullable', 'date'],
        ], [], [
            'name' => '氏名',
            'name_kana' => 'ふりがな',
            'department' => '主な所属',
            'hire_date' => '入社年月日',
        ]);

        // 送られてきた項目だけ更新（空文字は「クリア」として保存する）。
        if ($request->has('height')) {
            $person->height = $data['height'] ?? null;
        }
        if ($request->has('shoe_size')) {
            $person->shoe_size = $data['shoe_size'] ?? null;
        }
        if ($request->has('shirt_size')) {
            $person->shirt_size = $data['shirt_size'] ?? null;
        }

        // 「アサインの候補に出す／出さない」＝アサイン担当の操作なので管理者以上。
        if ($request->has('in_assign_pool')) {
            if (! in_array(optional(Auth::user())->permission, ['manager', 'admin'], true)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'アサイン表に出す／出さないを切り替えられるのは管理者以上です。',
                ], 403);
            }
            $person->in_assign_pool = (bool) $data['in_assign_pool'];
        }

        // 拠点（事務所）の付け替え＝管理者以上（2026-08-27 baba選択）。
        // ⚠ 拠点は案件一覧・名簿・集計の絞り込みに効くので、間違えると別の拠点のデータが見える。
        //   ⚠ 拠点マスタに無い名前は受け付けない（タイポで「どの拠点にも出てこない人」になるのを防ぐ）。
        if ($request->has('office')) {
            if (! in_array(optional(Auth::user())->permission, ['manager', 'admin'], true)) {
                return response()->json([
                    'ok' => false,
                    'message' => '拠点を直せるのは管理者以上です。',
                ], 403);
            }
            $office = trim((string) ($data['office'] ?? ''));
            if ($office !== '' && ! in_array($office, OfficeScope::options(), true)) {
                return response()->json([
                    'ok' => false,
                    'message' => "「{$office}」は拠点マスタにありません。マスタ管理で拠点を追加してから選んでください。",
                ], 422);
            }
            $person->office = $office !== '' ? $office : null;
        }

        // 入社年月日＝管理者以上（2026-08-28 baba要望）。
        // ⚠ 区分（新人／中堅／ベテラン）はこの日付から**その場で計算**している（保存していない）。
        //   なので入社日が間違っていると、新入社員がベテランに見える。直せる場所がここまで無かった。
        if ($request->has('hire_date')) {
            if (! in_array(optional(Auth::user())->permission, ['manager', 'admin'], true)) {
                return response()->json([
                    'ok' => false,
                    'message' => '入社年月日を直せるのは管理者以上です。',
                ], 403);
            }
            $person->hire_date = ($data['hire_date'] ?? null) ?: null;
        }

        // ここから下は他人の氏名・ふりがな・所属の書き換え＝Administrator だけ。
        $wantsIdentityChange = $request->hasAny(['name', 'name_kana', 'department', 'departments']);
        if ($wantsIdentityChange && optional(Auth::user())->permission !== 'admin') {
            return response()->json([
                'ok' => false,
                'message' => '氏名・ふりがな・所属を直せるのは Administrator だけです。',
            ], 403);
        }

        if ($request->has('name')) {
            $person->name = $data['name'];
        }
        if ($request->has('name_kana')) {
            $person->name_kana = ($data['name_kana'] ?? null) ?: null;
        }
        if ($request->has('department')) {
            $person->department = ($data['department'] ?? null) ?: null;
            // 兼務は主な所属を必ず含める形にそろえる（正本＝Departments::normalize）。
            $person->departments = Departments::normalize(
                $data['department'] ?? null,
                (array) ($data['departments'] ?? [])
            );
        }

        $person->save();

        return response()->json(['ok' => true]);
    }

    /**
     * スタッフ名簿の CSV 出力（GET /staff/export.csv）。
     * Excel でそのまま開けるよう UTF-8 BOM 付き。カンマ等は fputcsv が正しくエスケープする。
     */
    public function exportStaffCsv()
    {
        // できる役割の判定に staff_role_eligibility を一緒に読む（毎回引かないように）。
        $people = Person::staff()
            ->with('roleEligibilities')
            ->orderByDesc('experience_count')
            ->get();

        $rows = $people->map(function (Person $p) {
            $can = $p->roleEligibilities->pluck('position')->all();

            // 「できる役割」は D / MC / OP / 軍師 の4つだけを対象に表示する。
            $roleParts = [];
            if (in_array(AssignmentRole::D, $can, true)) {
                $roleParts[] = 'D';
            }
            if (in_array(AssignmentRole::MC, $can, true)) {
                $roleParts[] = 'MC';
            }
            if (in_array(AssignmentRole::OP, $can, true)) {
                // OP はオンライン/リアルの別があれば付記する。
                $flavor = '';
                if ($p->op_online && $p->op_real) {
                    $flavor = '（オンライン/リアル）';
                } elseif ($p->op_online) {
                    $flavor = '（オンライン）';
                } elseif ($p->op_real) {
                    $flavor = '（リアル）';
                }
                $roleParts[] = 'OP'.$flavor;
            }
            if (in_array(AssignmentRole::SP, $can, true)) {
                $roleParts[] = '軍師';
            }

            return [
                $p->id,
                $p->name,
                $p->office ?? '',
                $p->skill_level ?? '',   // 区分（新人/中堅/ベテラン）。hire_date から都度計算。
                implode('・', $roleParts),
                (int) ($p->experience_count ?? 0),
                $p->is_exclusive ? '専属' : '',
            ];
        });

        // BOM＋ヘッダ行＋データ行を組み立てる。
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM
        fputcsv($handle, ['ID', '氏名', '事務所', '区分', 'できる役割', '通算回数', '専属']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'staff_'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
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
                    'id' => $p->id,
                    'role' => 'staff',
                    'name' => $p->name,
                    'kana' => $p->name_kana ?? '',
                    'active' => (bool) $p->active,   // 在籍中か（false＝退職）
                    'email' => $p->email ?? '',
                    // ログインの状態（2026-08-25 baba要望＝名簿で「登録済み／まだ」が分かるように）
                    //   none    … アカウントがまだ無い（パスワードが空）
                    //   invited … 案内メールを送ったが、本人がまだパスワードを決めていない
                    //   ready   … 本人がパスワードを決めた＝ログインできる
                    'login' => $p->password_set_at ? 'ready' : ($p->invited_at ? 'invited' : ($p->password ? 'temp' : 'none')),
                    // 臨時スタッフ（インターン・知り合いの助っ人など）。ログインしない（2026-08-25 baba）。
                    'spot' => (bool) $p->is_spot,
                    'invitedAt' => optional($p->invited_at)->format('Y-m-d'),
                    'office' => $p->office ?? '',   // 事務所（地域オフィス）
                    // joinDate ＝ 区分（新人/中堅/ベテラン）計算の元。people.js と同じく文字列で渡す。
                    'joinDate' => $p->hire_date?->format('Y-m-d'),
                    'exclusive' => (bool) $p->is_exclusive,
                    'total' => $p->experience_count ?? 0,
                    'pos' => $pos,
                    // OPの区別（B案）：オンライン可／リアル可。null=未設定は false 扱いで渡す。
                    'opOnline' => (bool) $p->op_online,
                    'opReal' => (bool) $p->op_real,
                    'ng' => $p->ngRelations->pluck('partner_name')->all(),
                    'dnote' => $p->planner_impression ?? '',
                    'traits' => [
                        'follow' => (bool) $p->can_follow_newbie,
                        'starter' => (bool) $p->self_starter,
                        'atmos' => (bool) $p->improves_atmosphere,
                    ],
                    // 本人プロフィール（公開ボードの設定／初回設定で本人が入力・people の実列）。
                    // これまで名簿詳細は擬似ランダムの見本を出していたが、実データ表示に切り替える。
                    'profile' => [
                        'appeal' => $p->appeal ?? '',
                        'likeC' => $p->liked_contents ?? '',
                        'dislikeC' => $p->disliked_contents ?? '',
                        'strong' => $p->strong_positions ?? '',
                        'weak' => $p->weak_positions ?? '',
                        'height' => $p->height ?? '',
                        'shoe' => $p->shoe_size ?? '',
                        'shirt' => $p->shirt_size ?? '',
                        'pref' => $p->prefecture ?? '',
                        'station' => $p->nearest_station ?? '',
                        'mcPass' => (bool) $p->mc_audition_passed,
                        'kigurumi' => (bool) $p->can_kigurumi,
                        'stay' => (bool) $p->can_stay_over,
                        'drive' => $p->driving_level ?? '',
                        'english' => $p->english_level ?? '',
                    ],
                ];
            })
            ->values();

        // 「稼働状況」タブぶんのデータ。計算は StaffStatusController に一本化して再利用する。
        $status = app(StaffStatusController::class)->buildStatus();

        return view('staff', [
            'people' => $people,
            'status' => $status,
            // 経験回数（自動集計・2026-08-27 baba要望）。正本＝App\Support\ExperienceCount。
            // ⚠ 表に保存せず毎回数える（写しは必ず腐る）。一覧ぶんを1回のクエリでまとめて数える
            //   ＝1人ずつ引くと人数ぶんSQLが走る。
            'experience' => ExperienceCount::forMany($people->pluck('id')->all()),
            // 拠点で絞って見るための選択肢（2026-08-25 baba要望）。
            // 既定は自分の拠点。「すべての拠点」も選べる＝他拠点の人を探せなくならないように
            // （他拠点へヘルプに行く／来てもらう運用があるため）。
            'offices' => OfficeScope::options(),
            'myOffice' => OfficeScope::filterSingle(request()),
            // 「退職にする」「削除」を出すか＝Administrator だけ（権限4段階の決まり）。
            'canManagePeople' => optional(Auth::user())->permission === 'admin',
            // 拠点（事務所）を直せるか＝管理者以上（2026-08-27 baba選択）。
            // ⚠ canManagePeople（氏名・所属＝Administratorのみ）とは別の線引きなので分けて渡す。
            'canManageOffice' => in_array(optional(Auth::user())->permission, ['manager', 'admin'], true),
            // ログイン案内メールを送れるか＝管理者以上（アカウント発行と同じ扱い）。
            'canInvite' => in_array(optional(Auth::user())->permission, ['manager', 'admin'], true),
            'myId' => optional(Auth::user())->id,
        ]);
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
            'positions' => ['sometimes', 'array'],
            'positions.*' => ['string'],
            'managed_positions' => ['sometimes', 'array'],   // このフォームが扱うポジションの範囲（未指定＝全件置換）
            'managed_positions.*' => ['string'],
            'op_online' => ['sometimes', 'boolean'],  // OPオンライン可（B案）
            'op_real' => ['sometimes', 'boolean'],  // OPリアル(現地)可（B案）
            'ng' => ['nullable', 'string', 'max:2000'],
            'impression' => ['nullable', 'string', 'max:1000'],
            // 拠点（事務所）。2026-08-27 baba要望＝これまで画面から直せなかった。
            'office' => ['nullable', 'string', 'max:50'],
        ]);

        // 拠点（事務所）の付け替え＝管理者以上（2026-08-27 baba選択）。
        // ⚠ 拠点は案件一覧・名簿・集計の絞り込みに効くので、間違えると別の拠点のデータが見える。
        // ⚠ 拠点マスタに無い名前は受け付けない（タイポで「どの拠点にも出てこない人」になるのを防ぐ）。
        //   ※ people.office が空の人は自動で「東京」扱いになるため、空も許す（未設定に戻せる）。
        if ($request->has('office')) {
            if (! in_array(optional(Auth::user())->permission, ['manager', 'admin'], true)) {
                return response()->json([
                    'ok' => false,
                    'message' => '拠点を直せるのは管理者以上です。',
                ], 403);
            }
            $office = trim((string) ($data['office'] ?? ''));
            if ($office !== '' && ! in_array($office, OfficeScope::options(), true)) {
                return response()->json([
                    'ok' => false,
                    'message' => "「{$office}」は拠点マスタにありません。マスタ管理で拠点を追加してから選んでください。",
                ], 422);
            }
            $person->office = $office !== '' ? $office : null;
            $person->save();
        }

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
                    'staff_id' => $person->id,
                    'partner_name' => $name,
                    'partner_id' => Person::where('name', $name)->value('id'),
                    'relation_type' => 'NG',
                ]);
            }

            // 3) 専属・人柄・メモ（people の実在カラム）。
            $person->is_exclusive = $request->boolean('exclusive');
            $person->can_follow_newbie = $request->boolean('follow');
            $person->self_starter = $request->boolean('starter');
            $person->improves_atmosphere = $request->boolean('atmos');
            $person->planner_impression = $data['impression'] ?? null;
            // OPのオンライン/リアル可（B案）：送られてきたときだけ更新する。
            if ($request->has('op_online')) {
                $person->op_online = $request->boolean('op_online');
            }
            if ($request->has('op_real')) {
                $person->op_real = $request->boolean('op_real');
            }
            $person->save();
        });

        $message = $person->name.' さんの情報を保存しました。';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect('/staff')->with('status', $message);
    }

    /**
     * ログイン案内メールを送る。POST /people/{id}/invite（管理者以上）
     *
     * 使う場面（2026-08-25 baba要望）：
     *   スタッフは先に名簿だけ作っておき、メールアドレスをもらった人から順に案内を送りたい。
     *   そのため、この場でメールアドレスを登録してから送れるようにしている。
     *
     * ⚠ パスワードはメールに載せない。「自分でパスワードを決めるリンク」を送る
     *   （中身の判断・送信は App\Support\LoginInvite が正本）。
     */
    /**
     * 臨時スタッフをその場で名簿に足す（2026-08-25 baba要望）。
     *
     * インターンで今月だけ来る方、誰かの知り合いの助っ人など、
     * 名簿に無い人をアサイン画面から足せるようにする。中身は App\Support\SpotStaff が正本。
     *
     * ⚠ 名簿への登録は「管理者以上」（2026-07-02 確定の権限ルール）。
     *   ここもそれに合わせて tier:manager にしてある（routes/web.php）。
     */
    public function addSpot(Request $request)
    {
        // ⚠ $request->validate() は使わない。このアプリは JSON を返すのを api/* だけに絞っている
        //   （bootstrap/app.php の shouldRenderJsonWhen）ので、検証に落ちると画面用の
        //   リダイレクトが返ってしまい、呼んでいる側（fetch）が受け取れない。
        //   入力の確かめ方は App\Support\SpotStaff にまとめてある（名前が空・長すぎ・同姓同名）。
        $name = trim((string) $request->input('name', ''));

        // どの拠点の人として足すか。指定が無い／知らない拠点名なら自分の拠点にする
        // （知らない名前をそのまま入れると、どの拠点の名簿にも出てこない人になってしまう）。
        $office = trim((string) $request->input('office', ''));
        if ($office === '' || ! in_array($office, OfficeScope::options(), true)) {
            $office = OfficeScope::filterSingle($request);
        }

        $result = SpotStaff::create($name, $office);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'id' => $result['person']?->id,
        ], $result['ok'] ? 200 : 422);
    }

    public function sendInvite(Request $request, string $id)
    {
        $person = Person::find($id);
        if (! $person) {
            return response()->json(['ok' => false, 'message' => 'その人が見つかりませんでした。'], 404);
        }

        // メールアドレスが未登録なら、この場で登録してから送る（もらった順に送れるように）。
        if ($request->filled('email')) {
            $data = $request->validate([
                'email' => ['email', Rule::unique('people', 'email')->ignore($person->id, 'id')],
            ], [
                'email.unique' => 'このメールアドレスは別の方が使っています。',
            ], ['email' => 'メールアドレス']);

            $person->email = $data['email'];
            $person->save();
        }

        $result = LoginInvite::send($person);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * 在籍の切り替え（退職にする／在籍に戻す）。POST /people/{id}/active
     * Administrator のみ（権限4段階の決まり）。
     *
     * なぜ削除と分けるか＝辞めた人を「消す」と、その人が入った案件の記録
     * （アサイン・出勤数・収支）まで辿れなくなる。辞めた＝在籍を外す（active=false）が正しい。
     * 消すのは「間違えて登録した人」「テストで作った人」だけ。
     */
    public function setActive(Request $request, string $id)
    {
        $person = Person::find($id);
        if (! $person) {
            return response()->json(['ok' => false, 'message' => 'その人が見つかりませんでした。'], 404);
        }

        $active = $request->boolean('active');

        // 自分自身を退職にすると自分が入れなくなるので止める。
        if (! $active && $person->id === optional(Auth::user())->id) {
            return response()->json(['ok' => false, 'message' => '自分自身を退職にはできません。'], 422);
        }

        $person->active = $active;
        $person->save();

        return response()->json([
            'ok' => true,
            'active' => $active,
            'message' => $person->name.' さんを'.($active ? '在籍に戻しました。' : '退職（在籍なし）にしました。'),
        ]);
    }

    /**
     * 人を名簿から削除する。POST /people/{id}/delete
     * Administrator のみ（権限4段階の決まり）。
     *
     * ⚠ 実績（アサイン・エントリー・案件の担当）がある人は削除しない。
     *   消すと過去の案件から「誰が入ったか」が消え、出勤数や収支の集計も追えなくなるため。
     *   その場合は「退職にする」（在籍を外す）を案内する。
     *   ＝削除は「間違えて登録した人」「テストで作った人」を片づけるための機能。
     *
     * 消すときは、その人だけに付いている情報（できるポジション・NGペア・希望・経験・スキル）も
     * 一緒に片づける。編集履歴（誰がいつ何を変えたか）は記録なので消さない。
     */
    public function destroyPerson(string $id)
    {
        $person = Person::find($id);
        if (! $person) {
            return response()->json(['ok' => false, 'message' => 'その人が見つかりませんでした。'], 404);
        }

        // 自分自身は消せない。
        if ($person->id === optional(Auth::user())->id) {
            return response()->json(['ok' => false, 'message' => '自分自身は削除できません。'], 422);
        }

        // 最後の Administrator を消すと、誰も権限を直せなくなる。
        if ($person->permission === 'admin'
            && Person::where('permission', 'admin')->count() <= 1) {
            return response()->json([
                'ok' => false,
                'message' => '最後のAdministratorは削除できません（権限を管理できる人がいなくなります）。',
            ], 422);
        }

        // 実績があるかを数える。1つでもあれば削除しない。
        $blockers = [];
        $counts = [
            'アサイン' => DB::table('assignments')->where('staff_id', $person->id)->count(),
            'エントリー（応募）' => DB::table('applications')->where('staff_id', $person->id)->count(),
            '案件の担当（D/SD/物品）' => DB::table('projects')
                ->where('director_id', $person->id)
                ->orWhere('sd_id', $person->id)
                ->orWhere('goods_owner_id', $person->id)
                ->count(),
        ];
        foreach ($counts as $label => $n) {
            if ($n > 0) {
                $blockers[] = $label.' '.$n.'件';
            }
        }
        if ($blockers) {
            return response()->json([
                'ok' => false,
                'message' => $person->name.' さんには記録が残っているため削除できません（'
                    .implode('・', $blockers).'）。'
                    .'辞められた方の場合は「退職にする」を押してください。'
                    .'名簿には残りますが、アサインの候補には出なくなります。',
            ], 422);
        }

        $name = $person->name;

        // その人だけに付いている情報を片づけてから本人を消す。
        DB::transaction(function () use ($person) {
            foreach ([
                'staff_role_eligibility',
                'staff_relations',
                'shift_preferences',
                'staff_content_experience',
                'staff_role_experience',
                'staff_skills',
            ] as $table) {
                DB::table($table)->where('staff_id', $person->id)->delete();
            }
            // NGペアの相手側に自分が入っている行も消す（片方だけ残らないように）。
            DB::table('staff_relations')->where('partner_id', $person->id)->delete();

            $person->delete();
        });

        return response()->json(['ok' => true, 'message' => $name.' さんを名簿から削除しました。']);
    }
}
