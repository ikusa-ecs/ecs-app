<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\ShiftPreference;
use App\Support\AvailabilitySheetReader;
use App\Support\CsvText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 社員の出勤可能日のまとめて取込（/availability-import・2026-08-31 baba要望）。
 *
 * 【なぜ要るか】
 * 出勤可能日は `/employee-availability` で **社員本人が自分の月を1つずつ**入れる形しかなく、
 * 入れてくれない人がいると、いつまでも埋まらない。実際の運用では月別のスプレッドシートに
 * 全員ぶんが書かれているので、それをそのまま流し込めるようにする。
 *
 * 【安全のためにしていること】
 * ・**必ずプレビューを見せてから保存する**（誰の何日がどう入るかを見てから確定）。
 * ・**年月は人が選ぶ**。シートの見出しは「9/6」で年が無く、勘で決めると去年に入る。
 * ・**本人がすでに入れた日は書き換えない**（既定・baba選択）。「全部上書き」は明示のチェック。
 * ・名簿に見つからない名前は**入れずに一覧で知らせる**（似た名前へ寄せない）。
 * ・同姓同名が名簿に2人いる名前も入れない（どちらか決められないため）。
 */
class AvailabilityImportController extends Controller
{
    public function show()
    {
        return view('availability_import', ['period' => $this->defaultPeriod()]);
    }

    /** 取り込む前に、何がどう入るかを見せる。 */
    public function preview(Request $request)
    {
        [$rows, $period, $error, $raw] = $this->readInput($request);
        if ($error) {
            return back()->withInput()->with('import_error', $error);
        }

        $read = AvailabilitySheetReader::read($rows, $period);
        $plan = $this->buildPlan($read, $period, $request->boolean('overwrite'));

        return view('availability_import', [
            'period' => $period,
            'preview' => $plan,
            'overwrite' => $request->boolean('overwrite'),
            // 確定のときに読み直さなくて済むよう、読んだ中身をそのまま画面に持ち回す。
            // ⚠ CSVファイルで来たときも中身を文字にして持たせる＝確定でファイルを選び直さずに済む
            //   （プレビューで見たものと、保存するものが必ず同じになる）。
            'pasted' => $raw,
        ]);
    }

    /** プレビューの内容で保存する。 */
    public function import(Request $request)
    {
        [$rows, $period, $error] = $this->readInput($request);
        if ($error) {
            return back()->withInput()->with('import_error', $error);
        }

        $read = AvailabilitySheetReader::read($rows, $period);
        $overwrite = $request->boolean('overwrite');
        $plan = $this->buildPlan($read, $period, $overwrite);

        $saved = 0;
        DB::transaction(function () use ($plan, $period, $overwrite, &$saved) {
            foreach ($plan['rows'] as $r) {
                if ($r['personId'] === null) {
                    continue;
                }
                foreach ($r['days'] as $date => $d) {
                    if (! $overwrite && $d['skipped']) {
                        continue;   // 本人がすでに入れている日は触らない
                    }
                    // ⚠ updateOrCreate(['date' => 'Y-m-d']) は使わない。
                    //   date は日時として保存される（'2026-09-06 00:00:00'）ので、
                    //   'Y-m-d' の文字とは一致せず、**同じ日をもう一度入れると
                    //   unique(staff_id, date) に引っかかって500になる**。
                    //   whereDate で日付として引き当てる。
                    $row = ShiftPreference::where('staff_id', $r['personId'])
                        ->whereDate('date', $date)
                        ->first();

                    $values = [
                        'period' => $period,
                        'availability' => $d['availability'],
                        'day_note' => $d['memo'] !== '' ? $d['memo'] : null,
                        'note' => $r['note'],
                    ];

                    if ($row) {
                        $row->fill($values)->save();
                    } else {
                        ShiftPreference::create($values + ['staff_id' => $r['personId'], 'date' => $date]);
                    }
                    $saved++;
                }
            }
        });

        return redirect('/availability-import')
            ->with('status', "{$period} の出勤可能日を {$saved} 日ぶん取り込みました（".count($plan['rows'])."人）。");
    }

    /**
     * 入力（CSVファイル or 貼り付け）と年月を読む。
     *
     * @return array{0: array, 1: string, 2: ?string, 3: string}  [行, 年月, エラー文, 読んだ生の文字]
     */
    private function readInput(Request $request): array
    {
        $request->validate([
            'period' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'csv' => ['nullable', 'file', 'mimes:csv,txt'],
            'pasted' => ['nullable', 'string'],
        ], [], ['period' => '年月']);

        $period = (string) $request->input('period');

        // ファイルが選ばれていればそちらを優先。文字コードはここでUTF-8にそろえる
        // （ExcelがShift_JISで保存したCSVもそのまま読めるようにする）。
        if ($request->hasFile('csv')) {
            $raw = CsvText::toUtf8((string) file_get_contents($request->file('csv')->getRealPath()));

            return [CsvText::rowsPasted($raw), $period, null, $raw];
        }

        $pasted = (string) $request->input('pasted');
        if (trim($pasted) !== '') {
            // ⚠ 貼り付けた文字を trim() してはいけない（2026-08-31 に踏んだ）。
            //   スプレッドシートの選んだ範囲の**左端が空のセル**だと、1行目の先頭がタブで始まる。
            //   trim すると **1行目だけ** そのタブが消えて、見出しの列が1つ左にずれる
            //   ＝ 氏名の列と日付の列が全部ずれて、**1人も読めなくなる**（画面はエラーも出さない）。
            //   空かどうかの判定だけ trim して、渡すのは元の文字のまま。
            //   区切り文字（タブかカンマか）は CsvText が1行目で数えて決める。
            return [CsvText::rowsPasted($pasted), $period, null, $pasted];
        }

        return [[], $period, 'CSVファイルを選ぶか、スプレッドシートの中身を貼り付けてください。', ''];
    }

    /**
     * 読んだ結果 → 「誰の何日がどうなるか」の一覧。プレビューと保存で同じものを使う
     * ＝見た内容と入る内容が食い違わないようにする。
     */
    private function buildPlan(array $read, string $period, bool $overwrite): array
    {
        // 名簿にいる社員だけを対象にする（スタッフはこの画面の対象外）。
        $employees = Person::employees()->pluck('id')->all();

        // すでに入っている分（本人の入力を残すため）。
        $existing = ShiftPreference::where('period', $period)
            ->get(['staff_id', 'date', 'availability'])
            ->keyBy(fn ($r) => $r->staff_id.'|'.Carbon::parse($r->date)->format('Y-m-d'));

        $rows = [];
        $missing = [];
        $ambiguous = [];
        $notEmployee = [];

        foreach ($read['people'] as $p) {
            if ($p['ids'] === []) {
                $missing[] = $p['name'];
                continue;
            }
            if (count($p['ids']) > 1) {
                $ambiguous[] = $p['name'];
                continue;
            }
            $id = $p['ids'][0];
            if (! in_array($id, $employees, true)) {
                $notEmployee[] = $p['name'];
                continue;
            }

            $days = [];
            foreach ($p['days'] as $date => $d) {
                $days[$date] = [
                    'availability' => AvailabilitySheetReader::TO_DB[$d['code']],
                    'memo' => $d['memo'],
                    'skipped' => $existing->has($id.'|'.$date),
                ];
            }
            // 平日希望休は、上の〇×より優先しない（同じ日に両方あれば〇×を残す）。
            foreach ($p['offDays'] as $date) {
                if (! isset($days[$date])) {
                    $days[$date] = [
                        'availability' => AvailabilitySheetReader::TO_DB['off'],
                        'memo' => '',
                        'skipped' => $existing->has($id.'|'.$date),
                    ];
                }
            }
            ksort($days);

            $rows[] = [
                'name' => $p['name'],
                'personId' => $id,
                'days' => $days,
                'note' => $p['note'],
                'skipCount' => count(array_filter($days, fn ($d) => $d['skipped'])),
            ];
        }

        return [
            'rows' => $rows,
            'missing' => array_values(array_unique($missing)),
            'ambiguous' => array_values(array_unique($ambiguous)),
            'notEmployee' => array_values(array_unique($notEmployee)),
            'errors' => $read['errors'],
            'dates' => $read['dates'],
        ];
    }

    /** 既定の年月＝今月（画面を開いたときの初期値）。 */
    private function defaultPeriod(): string
    {
        return Carbon::today()->format('Y-m');
    }
}
