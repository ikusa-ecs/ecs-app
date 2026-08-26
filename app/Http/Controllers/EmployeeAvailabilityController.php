<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\ShiftPreference;
use App\Support\OfficeScope;
use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 社員の出勤可能日（参加希望）（/employee-availability）。S-018。
 *
 * 社員（people.role='employee'）が、月ごとに「イベントに入れる日」を登録する画面。
 * 画面の各日の状態（〇=出勤可 / ×=不可 / △=条件つき / 平日の希望休）を
 * shift_preferences テーブル（スタッフ×1日で1行）にそのまま保存・読込する。
 *
 * 【保存先テーブルの使い方（shift_preferences をそのまま流用）】
 *  ・staff_id     … 社員の people.id（このテーブルは「スタッフ」想定だが、社員も people なのでIDをそのまま入れる）
 *  ・period       … 対象年月（例 2026-07）
 *  ・date         … 対象の日（YYYY-MM-DD）
 *  ・availability … 画面の値を日本語へ対応づけ： ok→稼働可 / ng→NG / maybe→未定 / 平日希望休→希望休
 *  ・note         … その月の備考（memo）。社員×月で同じ文を各行に持たせ、読込時に拾う。
 *  ※ unique(staff_id, date) で同じ人×同じ日は1行＝入力し直しは上書き（save の updateOrCreate がこれに対応）。
 *
 * 表示はすべてDBが元。社員が0人・登録が0件でも見本（モック）には戻さない
 * （架空の社員や架空の〇×△が本物に見えてしまうため。2026-08-24）。
 */
class EmployeeAvailabilityController extends Controller
{
    /** 画面の値 → DBの availability 文字列 */
    private const TO_DB = [
        'ok' => '稼働可',
        'ng' => 'NG',
        'maybe' => '未定',
        'off' => '希望休',   // 平日の希望休
    ];

    /** DBの availability 文字列 → 画面の値（TO_DB の逆引き） */
    private const TO_VIEW = [
        '稼働可' => 'ok',
        '希望' => 'ok',     // 「希望」も画面では出勤可〇として扱う
        'NG' => 'ng',
        '未定' => 'maybe',
        '希望休' => 'off',
    ];

    public function index()
    {
        // 「自分」＝ログイン中の本人。画面はここに自分の入力を保存する。
        // ⚠ 以前は「社員一覧の先頭（＝E-001）」を自分として扱っていたため、
        //   誰がログインしても先頭の社員の名前で保存されてしまっていた（2026-08-24 修正）。
        $me = PersonalCases::meModel();

        // 本物の社員一覧（people.role='employee'）。画面の「全社員の一覧」タブの行になる。
        // 並びは社歴順（入社日の古い人が上）。画面は先頭行を「自分」として扱うので、
        // そのあとで自分だけを先頭に持ち上げる。
        $employees = Person::employees()
            ->bySeniority()
            ->get(['id', 'name', 'office'])
            // 拠点で絞って見られるように office も渡す（2026-08-26 baba要望）。
            // ⚠ 空の人は画面側で「東京」として扱う（名簿・案件と同じ決まり）。
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'office' => $p->office])
            ->sortBy(fn (array $e) => ($me && $e['id'] === $me->id) ? 0 : 1)
            ->values();

        // 登録済みの出勤可能日（全社員ぶん）。画面の state キー "YYYY-M-D" に合わせて整形する。
        // 形： { "E-001": { state: {"2026-7-13":"ok", ...}, memo: {"2026-7":"…"} }, ... }
        $prefs = [];
        foreach (ShiftPreference::all() as $sp) {
            $sid = $sp->staff_id;
            if (! isset($prefs[$sid])) {
                $prefs[$sid] = ['state' => [], 'memo' => []];
            }
            $d = $sp->date; // Carbon（モデルで date キャスト）
            if (! $d) {
                continue;
            }
            // 画面の keyOf は "Y-M-D"（M/D はゼロ埋めなし）なので合わせる。
            $stateKey = $d->year.'-'.$d->month.'-'.$d->day;
            $view = self::TO_VIEW[$sp->availability] ?? null;
            if ($view !== null) {
                $prefs[$sid]['state'][$stateKey] = $view;
            }
            // 備考はその月（period 由来）に1つ。空でなければ採用。
            $monKey = $d->year.'-'.$d->month;
            if (! empty($sp->note) && empty($prefs[$sid]['memo'][$monKey])) {
                $prefs[$sid]['memo'][$monKey] = $sp->note;
            }
        }

        return view('employee_availability', [
            'employees' => $employees,
            'prefs' => $prefs,
            // ログイン中の本人（保存先の staff_id はこの人）。未ログイン時は null。
            'me' => $me ? ['id' => $me->id, 'name' => $me->name] : null,
            // 「大型案件のある日」を出すための案件一覧（DBが元）。
            // 以前は凍結モック /ecs/data/cases.js を読んでいたため、架空の案件で大型の印が付いていた。
            'cases' => PersonalCases::cases(Carbon::today()),
            // 拠点で絞って見るための選択肢（2026-08-26 baba要望）。既定は自分の拠点。
            // ⚠ 拠点名は画面に書かない。正本は拠点マスタ（共通設定 → マスタ管理）。
            'offices' => OfficeScope::options(),
            'myOffice' => OfficeScope::filterSingle(request()),
        ]);
    }

    /**
     * 保存（POST /employee-availability/save）。
     * 受け取る JSON 例：
     *  { "employee_id":"E-001", "period":"2026-07",
     *    "state": { "2026-7-13":"ok", "2026-7-14":"off", ... },
     *    "memo": "今月は3件くらい参加したい" }
     *
     * state の各日を shift_preferences に upsert（unique(staff_id,date) で上書き）する。
     */
    public function save(Request $request)
    {
        // 保存先は必ずログイン中の本人。画面から送られてきた employee_id は当てにしない
        // （他人の出勤可能日を書き換えられないようにするため）。未ログイン時のみ受け取った値を使う。
        $me = PersonalCases::meModel();
        $employeeId = $me->id ?? $request->input('employee_id');
        $period = $request->input('period');          // 例 2026-07
        $state = (array) $request->input('state', []); // { "Y-M-D": "ok"|"ng"|"maybe"|"off" }
        $memo = $request->input('memo');

        if (! $employeeId || ! $period) {
            return response()->json(['ok' => false, 'message' => 'employee_id と period は必須です。'], 422);
        }

        // 念のため：本当に社員か確認（people に居て role=employee）
        $person = Person::employees()->find($employeeId);
        if (! $person) {
            return response()->json(['ok' => false, 'message' => '社員が見つかりません。'], 404);
        }

        $saved = 0;
        foreach ($state as $stateKey => $value) {
            $availability = self::TO_DB[$value] ?? null;
            if ($availability === null) {
                continue; // 未知の値は無視（不正データ混入の防止）
            }
            // "Y-M-D"（ゼロ埋めなし）→ Y-m-d の日付に直す。不正な日付は飛ばす。
            $parts = explode('-', (string) $stateKey);
            if (count($parts) !== 3) {
                continue;
            }
            try {
                $date = Carbon::create((int) $parts[0], (int) $parts[1], (int) $parts[2])->format('Y-m-d');
            } catch (\Throwable $e) {
                continue;
            }

            ShiftPreference::updateOrCreate(
                ['staff_id' => $employeeId, 'date' => $date], // unique キー
                [
                    'period' => $period,
                    'availability' => $availability,
                    'note' => $memo,
                ]
            );
            $saved++;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
