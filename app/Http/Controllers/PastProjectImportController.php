<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Support\AssignmentRole;
use App\Support\AssignmentStamp;
use App\Support\ClientName;
use App\Support\CsvText;
use App\Support\Headcount;
use App\Support\MonthlySheetReader;
use App\Support\OfficeScope;
use App\Support\PersonLookup;
use App\Support\ProjectImportColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 過去案件のCSV一括取込（/past-import）。2026-08-24 baba要望。
 *
 * 【これからの案件の取込（/project-import）と何が違うか】
 *  ・アサインも一緒に入れる … CSVの D／MC／OP／スタッフ の列に書かれた氏名を
 *    名簿と突き合わせて assignments に「確定」で登録する。
 *    （/project-import は「その時点ではDが決まっていない」ので取り込まない決まり）
 *  ・案件の状態は「確定」／スタッフに公開済み … 過去の実績なので、本人が自分の
 *    アサイン履歴として見られるようにする（baba確定）。
 *  ・同じ案件は上書き … 取り込み直しができるように。判定は
 *    「開催日・コンテンツ名・顧客名・集合時間」が全部同じかどうか。
 *    1つでも違えば別案件として新しく作る（同じ日・同じコンテンツでも顧客が違えば別案件）。
 *    ⚠ CSVの「No.」列は単なる行番号なので、判定には使わない（baba確認）。
 *
 * 【列の読み替え】
 *  App\Support\ProjectImportColumns を共用する＝アサイン表のリストのシートを
 *  そのままCSVにして入れられる。ECSに対応する項目が無い列は取り込まず、
 *  「取り込まなかった列」として画面に出す。
 */
class PastProjectImportController extends Controller
{
    /** 取込画面。 */
    public function show()
    {
        // CSVを選んだときの下見（誰が入る／名簿に無い）はサーバーの preview がまとめて返す
        // ＝読み取りの決まりを1か所にするため。画面へ渡すのは「どの拠点の案件として入れるか」だけ。
        return view('past_import', [
            // ⚠ 他拠点のアサイン表を、東京の人が代わりに取り込むことがある（2026-08-25 baba）。
            //   取り込んだ人の拠点で決め打ちにすると、東北の案件が東京の案件として入ってしまう。
            'offices' => OfficeScope::options(),
            'myOffice' => OfficeScope::filterSingle(request()),
        ]);
    }

    /**
     * CSVを読んで、過去案件とそのアサインを登録する。
     *
     * 2つの形を自動で見分ける（2026-08-25 baba要望）：
     *   ・list形式   … 1案件＝1行（202601_list のようなシート）
     *   ・月シート形式 … 1案件＝横1ブロック（202701 のような月ごとのシート）
     * どちらも同じ処理に流し込むため、読み取ったあとは「見出し＋値」の形にそろえる。
     */
    /**
     * CSVを読んで「1案件＝1件」の配列にそろえる。取込（import）と下見（preview）の共通部分。
     *
     * 2つの形を自動で見分ける（2026-08-25 baba要望）：
     *   ・list形式   … 1案件＝1行（202601_list のようなシート）
     *   ・月シート形式 … 1案件＝横1ブロック（202701 のような月ごとのシート）
     *
     * ⚠ ここを共通にしているのは、画面の下見とサーバーの取込で結果が食い違わないようにするため。
     *   （以前は画面側にも同じ読み取りをJavaScriptで書いていて、片方だけ直す事故が起きやすかった）
     *
     * @return array{error: ?string, isMonthly: bool, period: ?array{year:int,month:int},
     *               entries: list<array{label:string, header:list<string>, row:list<string>, people:?list<array{name:string,role:string}>}>,
     *               unmapped: list<string>}
     */
    private function readCsv(Request $request): array
    {
        $fail = fn (string $message) => [
            'error' => $message, 'isMonthly' => false, 'period' => null, 'entries' => [], 'unmapped' => [],
        ];

        // ⚠ 1行ずつ str_getcsv に渡さないこと。備考などのセルに改行が入っていると
        //   行がずれて別の項目を読んでしまう（2026-08-25 に実際に踏んだ）。
        //   CsvText::rows は引用符の中の改行を正しく扱う。文字コードも吸収する。
        $rows = CsvText::rows((string) file_get_contents($request->file('csv')->getRealPath()));

        if (count($rows) < 2) {
            return $fail('CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        $isMonthly = MonthlySheetReader::looksLikeMonthlySheet($rows);
        $entries = [];
        $unmapped = [];

        // 月シートの日程は「9月1日(火)」と年が書かれていないので、ファイル名から年を補う
        // （スプレッドシートから落とすと「〇〇 - 202701.csv」のようにシート名が入る・2026-08-25 baba）。
        $period = $isMonthly
            ? MonthlySheetReader::periodFromFilename($request->file('csv')->getClientOriginalName())
            : null;

        if ($isMonthly && $period === null) {
            return $fail('このCSVは月ごとのアサイン表に見えますが、ファイル名から「何年何月ぶんか」が読み取れませんでした。'
                .'日程に年が書かれていないため、年が分からないと取り込めません。'
                .'ファイル名にシート名（例：202701）が入るように、スプレッドシートから'
                .'「ファイル → ダウンロード → カンマ区切り形式」で落としたものをそのままお使いください。');
        }

        if ($isMonthly) {
            // 月シート＝項目名を探して読む（拠点で位置が少し違っても当たるように）。
            $read = MonthlySheetReader::read($rows);
            foreach ($read['cases'] as $i => $case) {
                $entries[] = [
                    'label' => ($i + 1).'件目',
                    'header' => array_keys($case['fields']),
                    'row' => array_values($case['fields']),
                    'people' => $case['people'],
                ];
            }
            $unmapped = $read['unknownLabels'];
        } else {
            // list形式＝1行目が見出し。
            $header = array_shift($rows);
            $resolved = ProjectImportColumns::resolve($header);
            $unmapped = $resolved['unmapped'];
            foreach ($rows as $i => $row) {
                if (implode('', array_map('trim', $row)) === '') {
                    continue;
                }
                $entries[] = [
                    'label' => ($i + 2).'行目',
                    'header' => $header,
                    'row' => $row,
                    'people' => null,     // null＝D/MC/OP/スタッフの列から作る
                ];
            }
        }

        return [
            'error' => null, 'isMonthly' => $isMonthly, 'period' => $period,
            'entries' => $entries, 'unmapped' => $unmapped,
        ];
    }

    /**
     * 1件ぶんの中身を取り出して、入れられるかどうかを見る。取込と下見で同じ判定を使う。
     *
     * @return array{name:string, date:string, rawDate:string, count:string, client:?string,
     *               meetTime:?string, errors: list<string>}
     */
    private function inspect(?array $period, callable $get, array $edit = []): array
    {
        // 画面で直した値があれば、そちらを使う（2026-08-25 baba要望）。
        // ⚠ 空にしたのも「直した」＝そのまま空として扱い、必須ならエラーにする
        //   （?? だと「空にした」が無視されてCSVの値に戻ってしまうので array_key_exists で見る）。
        $pick = fn (string $key, string $fallback) => array_key_exists($key, $edit)
            ? trim((string) $edit[$key])
            : $fallback;

        $name = $pick('name', $get('案件名'));
        $rawDate = $pick('date', $get('開催日'));
        // 月シートは年が無いので、ファイル名から読んだ年を補ってから解釈する。
        // 画面で直した日付は「2027-09-01」の形で来るので、そのまま通る。
        $date = ProjectImportColumns::normalizeDate(
            MonthlySheetReader::completeDate($rawDate, $period)
        );
        $count = $pick('count', $get('運営人数'));

        // 必須は「案件名」と「開催日」の2つ。
        // ⚠ 運営人数は、これからの案件では必須だが過去案件では空のことがあるので必須にしない
        //   （実績を入れるのが目的なので、人数が分からない過去案件を弾いてしまうと入らない）。
        $errors = [];
        if ($name === '') {
            $errors[] = 'コンテンツ（案件名）が空です';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->isRealDate($date)) {
            $errors[] = $rawDate !== ''
                ? "日程が読めません（{$rawDate}）。年から入れてください（例 2026-07-20）"
                : '日程が空です（例 2026-07-20）';
        }
        // 「5名」のように単位つきでも、「6〜8人」のような範囲でも読める。数字が1つも無ければエラー。
        if ($count !== '' && Headcount::parse($count)['max'] === null) {
            $errors[] = '運営人数が読めません（例 5 ／ 5名 ／ 6〜8人）';
        }

        return [
            'name' => $name,
            'date' => $date,
            'rawDate' => $rawDate,
            'count' => $count,
            'client' => ClientName::normalize($pick('client', $get('クライアント'))),
            'meetTime' => $get('集合時間') ?: null,
            'errors' => $errors,
        ];
    }

    /**
     * 下見（画面でファイルを選んだ瞬間に出す一覧）。登録はしない。
     *
     * なぜサーバーに投げるか＝読み取りの決まりを1か所にするため。
     * 画面のJavaScriptに同じ読み取りをもう1つ書くと、月シート対応のように
     * 片方だけ直して食い違う事故が起きる（CSV取込で実際に起きやすい）。
     */
    public function preview(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
            // 画面の表で直した内容（JSON）。直していなければ空。
            'edits' => ['nullable', 'string'],
        ]);

        $edits = $this->editsFromRequest($request);

        $read = $this->readCsv($request);
        if ($read['error'] !== null) {
            return response()->json(['ok' => false, 'message' => $read['error']]);
        }

        $people = PersonLookup::index();
        $rows = [];
        $missingNames = [];
        $ambiguousNames = [];
        $unknownRoles = [];

        foreach ($read['entries'] as $i => $entry) {
            $edit = $edits[$i] ?? [];
            $get = $this->cellReader($entry['header'], $entry['row']);
            $info = $this->inspect($read['period'], $get, $edit);

            // 誰が入るかも先に見せる（登録してから「名簿に無い」と分かると直すのに時間がかかるため）。
            $miss = [];
            $dup = [];
            $unknown = [];
            $assignments = $this->onePerPerson($entry['people'] === null
                ? $this->assignmentsFromColumns($get, $people, $miss, $dup)
                : $this->assignmentsFromPeople($entry['people'], $people, $miss, $dup, $unknown));

            foreach (array_keys($miss) as $n) {
                $missingNames[$n] = true;
            }
            foreach (array_keys($dup) as $n) {
                $ambiguousNames[$n] = true;
            }
            foreach ($unknown as $r) {
                $unknownRoles[$r] = true;
            }

            $rows[] = [
                // 何件目か＝画面で直した内容を、取り込みのときに同じ案件へ当てるための鍵。
                'index' => $i,
                // 「この件は取り込まない」に印を付けたかどうか（確かめ直しても印が消えないように返す）。
                'skip' => ! empty($edit['skip']),
                'label' => $entry['label'],
                'date' => $info['date'],
                'name' => $info['name'],
                'client' => $info['client'],
                'count' => $info['count'],
                'people' => count($assignments),
                'missing' => array_keys($miss),
                'ambiguous' => array_keys($dup),
                'errors' => $info['errors'],
            ];
        }

        return response()->json([
            'ok' => true,
            'isMonthly' => $read['isMonthly'],
            'rows' => $rows,
            'unmapped' => $read['unmapped'],
            'missing' => array_keys($missingNames),
            'ambiguous' => array_keys($ambiguousNames),
            'unknownRoles' => array_keys($unknownRoles),
        ]);
    }

    /**
     * CSVを読んで、過去案件とそのアサインを登録する。
     * 読み取りは readCsv（下見と同じ）。ここは登録だけを行う。
     */
    public function import(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
            // どの拠点の案件として入れるか（画面で選ぶ）。未指定は自分の拠点。
            'office' => ['nullable', 'string'],
            // 画面の表で直した内容（JSON）。直していなければ空。
            'edits' => ['nullable', 'string'],
        ]);

        $office = $this->targetOffice($request);
        $edits = $this->editsFromRequest($request);

        $read = $this->readCsv($request);
        if ($read['error'] !== null) {
            return redirect('/past-import')->with('import_error', $read['error']);
        }

        $isMonthly = $read['isMonthly'];
        $entries = $read['entries'];
        $unmapped = $read['unmapped'];
        $period = $read['period'];
        $unknownRoles = [];

        // 名簿の索引は1回だけ作って使い回す（案件ごとに引くと重いため）。
        $people = PersonLookup::index();

        $created = 0;
        $updated = 0;
        $assignCount = 0;
        $skipped = 0;
        $errors = [];
        $missingNames = [];     // 名簿に無かった人
        $ambiguousNames = [];   // 同姓同名で決められなかった人

        foreach ($entries as $i => $entry) {
            $edit = $edits[$i] ?? [];

            // 「この件は取り込まない」に印が付いていれば、まるごと飛ばす。
            if (! empty($edit['skip'])) {
                $skipped++;

                continue;
            }

            $get = $this->cellReader($entry['header'], $entry['row']);

            // 中身を取り出して、入れられるかどうかを見る（下見の画面とまったく同じ判定）。
            // 画面で直した値があれば、そちらを使う。
            $info = $this->inspect($period, $get, $edit);
            if ($info['errors']) {
                $errors[] = $entry['label']."（{$info['name']}）：".implode('／', $info['errors']);

                continue;
            }

            $name = $info['name'];
            $date = $info['date'];
            $client = $info['client'];
            $meetTime = $info['meetTime'];

            $attrs = $this->projectAttributes($get, $name, $date, $info['count'], $client, $meetTime, $office);

            // 同じ案件があるか＝開催日・コンテンツ名・顧客名・集合時間が全部同じ。
            // ⚠ 日付は「2026-01-20 00:00:00」の形で保存されるので、where ではなく whereDate で探す
            //   （where だと一致せず、取り込み直すたびに案件が増えてしまう。既知の罠）。
            $existing = Project::whereDate('start_date', $date)
                ->where('project_name', $name)
                ->where('client', $client)
                ->where('start_time', $meetTime)
                ->first();

            // この案件に入れるアサイン（氏名＋役割コード）を先に組み立てる。
            $assignments = $this->onePerPerson($entry['people'] === null
                ? $this->assignmentsFromColumns($get, $people, $missingNames, $ambiguousNames)
                : $this->assignmentsFromPeople($entry['people'], $people, $missingNames, $ambiguousNames, $unknownRoles));

            DB::transaction(function () use ($existing, $attrs, $date, $assignments, &$created, &$updated, &$assignCount) {
                if ($existing) {
                    $existing->fill($attrs)->save();
                    $project = $existing;
                    $updated++;
                } else {
                    $project = Project::create($attrs + ['id' => $this->nextProjectId($date)]);
                    $created++;
                }

                // その案件のこの取込ぶんは作り直す（取り込み直しで二重にならないように）。
                // ⚠ 消すのは「この取込が入れる役割」で、しかも「この日」だけ。
                //   他の画面で入れた役割や、同じ案件の別の日は消さない。
                $roles = array_values(array_unique(array_column($assignments, 'role')));
                if ($roles) {
                    Assignment::where('project_id', $project->id)
                        ->whereDate('date', $date)
                        ->whereIn('role', $roles)
                        ->delete();
                }

                foreach ($assignments as $a) {
                    // 同じ案件×同じ人×同じ日は1行（unique）。取り違え防止でIDで入れる。
                    // 「誰がいつ確定したか」の記録は AssignmentStamp が正本（2026-08-20）。
                    // ⚠ 既存行を探すときは whereDate で「日付部分」だけ照合する。
                    //   DBには「2027-09-01 00:00:00」の形で入るので、文字列の完全一致だと
                    //   取りこぼして新規作成→重複エラーになる（2026-08-25 に実際に踏んだ既知の罠）。
                    $row = Assignment::where('project_id', $project->id)
                        ->where('staff_id', $a['id'])
                        ->whereDate('date', $date)
                        ->first();
                    if ($row) {
                        $row->update(['role' => $a['role'], 'status' => '確定']
                            + AssignmentStamp::forUpdate($row, '確定'));
                    } else {
                        Assignment::create([
                            'project_id' => $project->id,
                            'staff_id' => $a['id'],
                            'date' => $date,
                            'role' => $a['role'],
                            'status' => '確定',
                        ] + AssignmentStamp::forCreate('確定'));
                    }
                    $assignCount++;
                }
            });
        }

        return redirect('/past-import')
            ->with('status', $this->buildMessage($isMonthly, $created, $updated, $assignCount, $skipped, $errors, $unmapped))
            ->with('past_missing', array_keys($missingNames))
            ->with('past_ambiguous', array_keys($ambiguousNames))
            ->with('past_unknown_roles', array_values(array_unique($unknownRoles)));
    }

    /**
     * 「見出し＋値」から、ECSの正式な項目名で値を取り出す関数を作る。
     * 読み替え（日程→開催日 など）は ProjectImportColumns が正本。
     */
    private function cellReader(array $header, array $row): callable
    {
        $map = ProjectImportColumns::resolve($header)['map'];

        return function (string $name) use ($map, $row) {
            $i = $map[$name] ?? null;
            $value = ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
            if ($value === '') {
                return '';
            }
            if (in_array($name, ProjectImportColumns::TIME_COLUMNS, true)) {
                return ProjectImportColumns::normalizeTime($value) ?: $value;
            }

            return $value;
        };
    }

    /**
     * list形式のアサイン＝D／MC／OP／スタッフ の列に書かれた氏名から作る。
     * ⚠ 「スタッフ」列は役割が書かれていないので FC として入れる（baba確定）。
     *
     * @return list<array{id:string, role:string}>
     */
    private function assignmentsFromColumns(callable $get, array $people, array &$missing, array &$ambiguous): array
    {
        $out = [];
        foreach (ProjectImportColumns::ASSIGN_COLUMNS as $column => $role) {
            $cell = $get($column);
            if ($cell === '') {
                continue;
            }
            $found = PersonLookup::resolveNames($cell, $people);
            foreach ($found['missing'] as $n) {
                $missing[$n] = true;
            }
            foreach ($found['ambiguous'] as $n) {
                $ambiguous[$n] = true;
            }
            foreach ($found['ids'] as $id) {
                $out[] = ['id' => $id, 'role' => $role];
            }
        }

        return $out;
    }

    /**
     * 月シート形式のアサイン＝1人ずつ「氏名＋役割」が書かれているのでそのまま使う。
     * list形式より正確に入る（list は「スタッフ」列がまとめて FC になる）。
     *
     * ⚠ 知らない役割の書き方は、勝手に決めずに入れない＝一覧で知らせる（baba要望）。
     *   役割が空欄のときは「現場」として FC で入れる（誰が入ったかは残したいため）。
     *
     * @return list<array{id:string, role:string}>
     */
    private function assignmentsFromPeople(array $rows, array $people, array &$missing,
        array &$ambiguous, array &$unknownRoles): array
    {
        $out = [];
        foreach ($rows as $person) {
            $found = PersonLookup::resolveNames($person['name'], $people);
            foreach ($found['missing'] as $n) {
                $missing[$n] = true;
            }
            foreach ($found['ambiguous'] as $n) {
                $ambiguous[$n] = true;
            }
            if ($found['ids'] === []) {
                continue;
            }

            $raw = trim((string) ($person['role'] ?? ''));
            if ($raw === '') {
                $role = AssignmentRole::FC;      // 役割が空＝現場として残す
            } else {
                $role = AssignmentRole::fromLabel($raw);
                if ($role === null) {
                    $unknownRoles[] = $raw;      // 知らない書き方＝入れずに知らせる
                    continue;
                }
            }

            foreach ($found['ids'] as $id) {
                $out[] = ['id' => $id, 'role' => $role];
            }
        }

        return $out;
    }

    /**
     * 1行 → projects に入れる値。
     * 通常の取込と同じ読み替えを使うが、状態は「確定」・公開済みにする（過去の実績なので）。
     */
    private function projectAttributes(callable $get, string $name, string $date,
        string $count, ?string $client, ?string $meetTime, string $office): array
    {
        $guests = $this->digits($get('お客様人数'));
        $teams = $this->digits($get('チーム数'));

        return [
            'project_name' => $name,
            // 登録拠点＝画面で選んだ拠点（既定は取り込んだ人の拠点）。
            // ⚠ ここが空だと案件一覧の拠点しぼりに引っかからず、誰にも見えない案件になる。
            // ⚠ 他拠点のアサイン表を代わりに取り込むことがあるので、取り込んだ人の拠点で
            //   決め打ちにしない（東北の案件が東京の案件として入ってしまう・2026-08-25 baba）。
            'office' => $office,
            'content_ids' => $this->resolveContentIds($name),
            'content_names' => [$name],
            'category' => $get('区分') ?: null,
            'yomi' => $get('確度') ?: '確定',
            'scale' => $get('案件規模') ?: null,
            // 過去案件なので募集はしない（スタッフ画面に「募集中」として出さないため）。
            'is_recruiting' => false,
            'is_multi' => $get('複数案件') === 'あり',
            'date_type' => $get('日程種別') ?: '本番',
            'sales_owners' => $get('営業担当') ? [$get('営業担当')] : null,
            'format' => $get('実施形態') ?: null,
            'online_tool' => $get('オンラインツール') ?: null,
            'broadcast' => $get('配信種別') ?: null,
            'operation_place' => $get('運営場所') ?: null,
            'client' => $client,
            'agency' => $get('代理店名') ?: null,
            'staff_role' => $get('担当体制') ?: null,
            'start_date' => $date,
            'start_time' => $meetTime,
            'end_time' => $get('解散時間') ?: null,
            'event_enter_time' => $get('イベント入場') ?: null,
            'event_start_time' => $get('イベント開始') ?: null,
            'event_end_time' => $get('イベント終了') ?: null,
            'location' => $get('会場住所') ?: null,
            'is_outdoor' => $get('屋内外') ? ($get('屋内外') === '屋外') : null,
            'lodging' => $get('宿泊') ?: null,
            'assembly_type' => $get('集合形式') ?: null,
            // ⚠ 数字だけ抜き出すと「6〜8」が「68」になる。範囲の読み取りは Headcount が正本。
            'required_count' => Headcount::parse($count)['max'],
            'required_count_min' => Headcount::parse($count)['min'],
            'guest_count' => $guests !== '' ? (int) $guests : null,
            'team_count' => $teams !== '' ? (int) $teams : null,
            'is_repeat' => $get('リピート') === 'あり',
            'alcohol' => $get('お酒') ? ($get('お酒') === 'あり') : null,
            'catering' => $get('ケータリング') ?: null,
            'audio_equipment' => $get('音響機材') ?: null,
            'transport' => $get('移動車両') ?: null,
            'pub_logo' => $get('ロゴ') ?: null,
            'pub_camera' => $get('カメラ') ?: null,
            'pub_article' => $get('事例記事') ?: null,
            'pub_video' => $get('動画') ?: null,
            'goods_owner_id' => $this->personIdByName($get('物品担当')),
            'ops_sheet_url' => $get('運営シートURL') ?: null,
            'prep_line_created' => $this->isDone($get('準備:LINE作成')),
            'prep_line_sent' => $this->isDone($get('準備:LINE概要送付')),
            'prep_line_double_check' => $this->isDone($get('準備:LINEダブルチェック')),
            'prep_handover' => $this->isDone($get('準備:引き継ぎ')),
            'prep_script' => $this->isDone($get('準備:台本')),
            'note' => $get('備考') ?: null,
            // 過去の実績＝確定・公開済み（本人が自分の履歴として見られるように）。
            'status' => '確定',
            'staff_published' => true,
        ];
    }

    /**
     * 画面の表で直した内容を受け取る（2026-08-25 baba要望）。
     *
     * 【なぜ「直した分だけ」を送るのか】
     * CSVそのものは今までどおりサーバーが読む。画面から送るのは「上書きする値」だけにして、
     * 読み取りの決まりを増やさない。⚠ 画面にもう1つ読み取りを書くと、片方だけ直して
     * 食い違う事故が起きる（この画面で実際に起きやすい）。
     *
     * 形： {"0":{"date":"2027-09-01","name":"謎解き","client":"〇〇株式会社","count":"5","skip":false}, ...}
     * 鍵は「CSVの何件目か」（0から数える）。
     *
     * @return array<int, array{date?:string,name?:string,client?:string,count?:string,skip?:bool}>
     */
    private function editsFromRequest(Request $request): array
    {
        $raw = $request->input('edits');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];   // 壊れていたら「直していない」と同じ扱い＝CSVのまま取り込む
        }

        $edits = [];
        foreach ($decoded as $index => $edit) {
            if (! is_array($edit) || ! is_numeric($index)) {
                continue;
            }
            $clean = [];
            foreach (['date', 'name', 'client', 'count'] as $key) {
                if (array_key_exists($key, $edit) && is_scalar($edit[$key])) {
                    $clean[$key] = (string) $edit[$key];
                }
            }
            if (! empty($edit['skip'])) {
                $clean['skip'] = true;
            }
            if ($clean !== []) {
                $edits[(int) $index] = $clean;
            }
        }

        return $edits;
    }

    /**
     * 取り込んだ案件を「どの拠点の案件」として入れるか。
     *
     * 画面で選んだ拠点。選ばれていない・知らない拠点名のときは自分の拠点にする
     * （知らない名前をそのまま入れると、案件一覧の拠点しぼりに引っかからず
     *   誰にも見えない案件になってしまうため）。
     */
    private function targetOffice(Request $request): string
    {
        $sent = trim((string) $request->input('office', ''));

        if ($sent !== '' && in_array($sent, OfficeScope::options(), true)) {
            return $sent;
        }

        return OfficeScope::filterSingle($request);
    }

    /**
     * 同じ人が同じ案件に2回出てきたら1行にまとめる（後に書かれている役割を採用）。
     *
     * なぜ要るか＝assignments は「案件×人×日」で1行と決まっている（unique）。
     * アサイン表で同じ人が2か所に書かれていることがあるので、ここでまとめておかないと
     * 「5件入れました」と出るのに実際は2件、のように数が合わなくなる。
     *
     * @param  list<array{id:string, role:string}>  $assignments
     * @return list<array{id:string, role:string}>
     */
    private function onePerPerson(array $assignments): array
    {
        $byId = [];
        foreach ($assignments as $a) {
            $byId[$a['id']] = $a;
        }

        return array_values($byId);
    }

    /**
     * 「5名」「10チーム」「50名」から数字だけ取り出す。数字が無ければ空文字。
     *
     * なぜ要るか＝月ごとのアサイン表は「5名」「10チーム」のように単位つきで書かれている
     * （list形式は数字だけ）。どちらでも読めるようにする（2026-08-25）。
     */
    private function digits(string $value): string
    {
        $v = preg_replace('/[^0-9]/u', '', trim($value));

        return $v === null ? '' : $v;
    }

    /** 「済」「○」などが入っていれば「やった」と見なす。 */
    private function isDone(string $value): bool
    {
        return in_array($value, ['済', '○', '◯', '✓', '有', 'あり', 'はい', '1', 'yes', 'OK'], true);
    }

    /** コンテンツ名 → content_ids（台帳に無ければ発番して追加）。 */
    private function resolveContentIds(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $existing = Content::where('content_name', $name)->first();
        if ($existing) {
            return [$existing->id];
        }

        $maxNum = (int) (Content::where('id', 'like', 'CT-%')->get()
            ->map(fn ($c) => (int) preg_replace('/\D/', '', $c->id))
            ->max() ?? 0);
        $id = 'CT-'.str_pad((string) ($maxNum + 1), 3, '0', STR_PAD_LEFT);

        Content::create([
            'id' => $id,
            'content_name' => $name,
            'active' => true,
            // 台帳の末尾に置く（並び順は数字が大きいほど後ろ）。
            'sort_order' => (int) (Content::max('sort_order') ?? 0) + 10,
        ]);

        return [$id];
    }

    /** 案件IDを発番（P-西暦-連番）。 */
    private function nextProjectId(string $startDate): string
    {
        $prefix = 'P-'.Carbon::parse($startDate)->year.'-';
        $maxSeq = Project::where('id', 'like', $prefix.'%')->get()
            ->map(fn ($p) => (int) substr($p->id, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    /** 氏名 → 名簿のID（1人に決まるときだけ）。物品担当に使う。 */
    private function personIdByName(string $name): ?string
    {
        if (trim($name) === '') {
            return null;
        }
        $found = PersonLookup::resolveNames($name, PersonLookup::index());

        return $found['ids'][0] ?? null;
    }

    /** YYYY-MM-DD が実在する日付か。 */
    private function isRealDate(string $date): bool
    {
        [$y, $m, $d] = array_pad(explode('-', $date), 3, '0');

        return checkdate((int) $m, (int) $d, (int) $y);
    }

    /** 画面に出す結果のメッセージ。 */
    private function buildMessage(bool $isMonthly, int $created, int $updated, int $assignCount,
        int $skipped, array $errors, array $unmapped): string
    {
        $msg = $isMonthly
            ? '月ごとのアサイン表として読みました。'
            : 'list形式（1案件＝1行）として読みました。';
        $msg .= "過去案件を{$created}件 新しく登録しました";
        if ($updated > 0) {
            $msg .= "（同じ案件だった{$updated}件は上書きしました）";
        }
        $msg .= "。アサインは{$assignCount}件を「確定」で入れました。";

        if ($skipped > 0) {
            $msg .= " 「取り込まない」に印を付けた{$skipped}件は入れていません。";
        }

        if ($errors) {
            $msg .= ' エラー'.count($errors).'件は取り込みませんでした：'.implode(' / ', $errors);
        }
        if ($unmapped) {
            $msg .= ' ※ 取り込まなかった項目：'.implode('・', $unmapped)
                .'（ECSに対応する項目がありません。必要なら教えてください）';
        }

        return $msg;
    }
}
