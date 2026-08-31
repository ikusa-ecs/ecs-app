<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\ShiftPreference;
use App\Support\ConfirmedSchedule;
use App\Support\OfficeScope;
use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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
        // ⚠ 「アサインの候補に出さない」社員は一覧に並べない（2026-08-26 baba要望）。
        //   自分は対象外にしていても必ず残す＝画面は「先頭の行＝自分」という決まりで
        //   動いているので、自分が消えると他人の行に自分の入力が出てしまう。
        $employees = Person::employees()
            ->inAssignPool($me ? [$me->id] : [])
            ->bySeniority()
            ->get(['id', 'name', 'office'])
            // 拠点で絞って見られるように office も渡す（2026-08-26 baba要望）。
            // ⚠ 空の人は画面側で「東京」として扱う（名簿・案件と同じ決まり）。
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name, 'office' => $p->office])
            ->sortBy(fn (array $e) => ($me && $e['id'] === $me->id) ? 0 : 1)
            ->values();

        // 登録済みの出勤可能日（全社員ぶん）。画面の state キー "YYYY-M-D" に合わせて整形する。
        // 形： { "E-001": { state: {"2026-7-13":"ok", ...}, memo: {"2026-7":"…"}, dayNote: {"2026-7-13":"午後だけ"} }, ... }
        $hasDayNote = Schema::hasColumn('shift_preferences', 'day_note');
        $prefs = [];
        foreach (ShiftPreference::all() as $sp) {
            $sid = $sp->staff_id;
            if (! isset($prefs[$sid])) {
                $prefs[$sid] = ['state' => [], 'memo' => [], 'dayNote' => []];
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
            // その日のメモ（〇×△とは別。まだ migrate していないサーバーでは列が無いので確認する）。
            if ($hasDayNote && trim((string) $sp->day_note) !== '') {
                $prefs[$sid]['dayNote'][$stateKey] = (string) $sp->day_note;
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
            // 「その日にもう決まっている案件」（2026-08-28 baba要望）。
            // ⚠ 保存しない＝開くたびに数え直す。希望を出したあとに決まった案件も、
            //   次に開けば自動で出る（希望を出す時点では案件があるか分からないため）。
            'assigned' => ConfirmedSchedule::forPeople($employees->pluck('id')->all()),
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

        // その月ぶんを「日付 => 入れる中身」に組み立て直す。
        // ⚠ 〇×△とメモは別々に送られてくるので、同じ日を2回 updateOrCreate しないよう先にまとめる。
        $byDate = [];
        foreach ($state as $stateKey => $value) {
            $availability = self::TO_DB[$value] ?? null;
            if ($availability === null) {
                continue; // 未知の値は無視（不正データ混入の防止）
            }
            $date = self::toDate($stateKey);
            if ($date !== null) {
                $byDate[$date]['availability'] = $availability;
            }
        }
        // その日のメモ（2026-08-28 追加）。空文字で送られてきたら「消す」＝ null にする。
        $hasDayNote = Schema::hasColumn('shift_preferences', 'day_note');
        if ($hasDayNote) {
            foreach ((array) $request->input('day_notes', []) as $stateKey => $text) {
                $date = self::toDate($stateKey);
                if ($date !== null) {
                    $text = trim((string) $text);
                    $byDate[$date]['day_note'] = $text !== '' ? $text : null;
                }
            }
        }

        $saved = 0;
        foreach ($byDate as $date => $values) {
            // ⚠ updateOrCreate(['date' => 'Y-m-d']) は使えない（2026-08-31 修正）。
            //   date は**日時**として保存される（'2026-09-06 00:00:00'）ので、'Y-m-d' の文字とは
            //   一致せず、既にある行を見つけられない → 新しく作ろうとして
            //   unique(staff_id, date) に引っかかり **500になって保存できない**。
            //   ＝ 同じ月をもう一度保存すると必ず失敗していた。whereDate で日付として引き当てる。
            //   （同じ罠は AssignDirectorController でも踏んでいる＝date をキーにするときの決まり）
            $row = ShiftPreference::where('staff_id', $employeeId)
                ->whereDate('date', $date)
                ->first();

            $attrs = array_merge(['period' => $period, 'note' => $memo], $values);

            if ($row) {
                $row->fill($attrs)->save();
            } else {
                ShiftPreference::create($attrs + ['staff_id' => $employeeId, 'date' => $date]);
            }
            $saved++;
        }

        // ⚠ 送られてこなかった日は「未入力に戻した日」＝画面から消えている。
        //   以前はここを何もしていなかったので、**一度付けた〇を消しても消えなかった**
        //   （画面では消えているのに、開き直すと〇が復活する。2026-08-28 修正）。
        //   メモも〇×△も無くなった行は消し、メモだけ残っている行は〇×△だけ空にする。
        $cleared = self::clearMissingDays($employeeId, $period, array_keys($byDate), $hasDayNote);

        return response()->json(['ok' => true, 'saved' => $saved, 'cleared' => $cleared]);
    }

    /**
     * 画面のキー "Y-M-D"（ゼロ埋めなし）→ "Y-m-d"。おかしな値は null。
     */
    private static function toDate(string $stateKey): ?string
    {
        $parts = explode('-', $stateKey);
        if (count($parts) !== 3) {
            return null;
        }
        try {
            return Carbon::create((int) $parts[0], (int) $parts[1], (int) $parts[2])->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * その月のうち、今回送られてこなかった日を片付ける。
     *
     * ⚠ 対象はその月だけ。画面はその月ぶんしか送ってこないので、
     *   他の月まで見に行くと、開いていない月の入力を消してしまう。
     *
     * @param  list<string>  $keptDates  今回保存した日（Y-m-d）
     */
    private static function clearMissingDays(string $employeeId, string $period, array $keptDates, bool $hasDayNote): int
    {
        try {
            $month = Carbon::createFromFormat('Y-m-d', $period.'-01');
        } catch (\Throwable $e) {
            return 0; // period の形がおかしいときは何もしない（消しすぎないように）
        }

        $rows = ShiftPreference::where('staff_id', $employeeId)
            ->whereBetween('date', [$month->copy()->startOfMonth()->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get();

        $cleared = 0;
        foreach ($rows as $row) {
            $date = $row->date ? $row->date->format('Y-m-d') : null;
            if ($date === null || in_array($date, $keptDates, true)) {
                continue;
            }
            // メモが残っているなら行は残す（〇×△だけ空にする）。
            if ($hasDayNote && trim((string) $row->day_note) !== '') {
                if ($row->availability !== null) {
                    // ⚠ note（その月の備考）は消さない。消すと、〇を1つ外しただけで
                    //   その月に書いた備考まで消えてしまう。
                    $row->availability = null;
                    $row->save();
                    $cleared++;
                }

                continue;
            }
            $row->delete();
            $cleared++;
        }

        return $cleared;
    }
}
