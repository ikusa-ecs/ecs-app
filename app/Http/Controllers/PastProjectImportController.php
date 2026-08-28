<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Models\ProjectShare;
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
use Illuminate\Support\Facades\Auth;
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
    /**
     * この表の扱い（2026-08-26 baba要望）。
     *
     * 【なぜ2つ要るか】
     * 同じ「アサイン表」を取り込むのに、終わった案件とこれからの案件で入れ方が正反対になる。
     *   ・終わった案件 … 実績なので 確定・公開済み・募集しない／アサインも確定
     *   ・これからの案件 … まだ動くので 調整中・未公開・募集する／アサインは仮
     * これが選べないと「これからの10月のアサイン表を入れると全部確定になってしまう」か
     * 「案件CSV取込では人が入らない」かの二択になる（baba指摘のジレンマ）。
     *
     * ⚠ CSVの読み取りは1か所のまま。切り替わるのは「入れるときの値」だけ。
     */
    public const MODE_PAST = '過去';

    public const MODE_FUTURE = 'これから';

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
            // 終わった案件か、これからの案件か（既定＝今までどおり「過去」）。
            'modePast' => self::MODE_PAST,
            'modeFuture' => self::MODE_FUTURE,
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

        // 入れ方は2通り（2026-08-27 baba要望）。
        //   ①ファイルを選ぶ … スプレッドシートからCSVで落としたもの
        //   ②貼り付ける   … スプレッドシートでセルをコピーして貼ったもの（タブ区切り）
        // ⚠ 読み取りから先は**まったく同じ道**を通す。ここで分かれるのは「文字をどう取るか」と
        //   「何年何月ぶんか」の決め方だけ＝2つの読み取りを持たない（食い違いの元）。
        $pasted = trim((string) $request->input('paste', ''));
        $isPaste = $pasted !== '' && $request->file('csv') === null;

        if ($isPaste) {
            // 貼り付けはタブ区切り。セルの中に改行やカンマが入っていても壊れない。
            $rows = CsvText::rowsPasted($pasted);
        } else {
            // ⚠ 1行ずつ str_getcsv に渡さないこと。備考などのセルに改行が入っていると
            //   行がずれて別の項目を読んでしまう（2026-08-25 に実際に踏んだ）。
            //   CsvText::rows は引用符の中の改行を正しく扱う。文字コードも吸収する。
            $rows = CsvText::rows((string) file_get_contents($request->file('csv')->getRealPath()));
        }

        if (count($rows) < 2) {
            return $fail($isPaste
                ? '貼り付けた中身が1行しかありません。アサイン表の案件のかたまり（縦1列ぶん）を選んでコピーしてください。'
                : 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        $isMonthly = MonthlySheetReader::looksLikeMonthlySheet($rows);
        $entries = [];
        $unmapped = [];

        // 月シートの日程は「9月1日(火)」と年が書かれていないので、年月を別に決める。
        //   ファイル … 名前から読む（落とすと「〇〇 - 202701.csv」のようにシート名が入る・2026-08-25 baba）
        //   貼り付け … 名前が無いので、画面で選んでもらう
        $period = null;
        if ($isMonthly) {
            $period = $isPaste
                ? MonthlySheetReader::periodFromFilename((string) $request->input('period'))
                : MonthlySheetReader::periodFromFilename($request->file('csv')->getClientOriginalName());
        }

        if ($isMonthly && $period === null) {
            return $fail($isPaste
                ? '「何年何月ぶんか」を選んでください。アサイン表の日程には年が書かれていないため、'
                    .'年が分からないと取り込めません。'
                : 'このCSVは月ごとのアサイン表に見えますが、ファイル名から「何年何月ぶんか」が読み取れませんでした。'
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
                    // 「メンバー」と書いてあるだけの空き枠の数（運営人数が空のときに使う）。
                    'slots' => $case['slots'] ?? 0,
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
                    'slots' => 0,
                ];
            }
        }

        return [
            'error' => null, 'isMonthly' => $isMonthly, 'period' => $period,
            'entries' => $entries, 'unmapped' => $unmapped,
        ];
    }

    /**
     * 案件登録の「アサイン表から貼り付け」（POST /project-form/paste）。2026-08-27 baba要望。
     *
     * 【何をするか】
     * スプレッドシートで**1案件ぶんのかたまり**を選んでコピーし、案件登録画面に貼ると、
     * 各欄が埋まった状態になる。**登録はしない**（人が見て直してから保存を押す）。
     *
     * 【なぜ登録しないか】
     * 一括取込（この画面）は「まとめて入れる」ためのもの。こちらは「1件だけ足したい」ときのもので、
     * 貼ったあとに手で足す項目（ARENAの詳細など）があるため、フォームに流し込むところまでにする。
     *
     * ⚠ 読み取りは一括取込とまったく同じ道（readCsv → inspect → projectAttributes）を通す。
     *   ここに別の読み取りを書くと、片方だけ直して食い違う（この取込で何度も踏んでいる事故）。
     */
    public function pasteOne(Request $request)
    {
        $request->validate([
            'paste' => ['required', 'string'],
            // 貼り付けはファイル名が無いので「何年何月ぶんか」を画面から受け取る（例 2026-09）。
            'period' => ['nullable', 'string'],
        ]);

        $read = $this->readCsv($request);
        if ($read['error'] !== null) {
            return response()->json(['ok' => false, 'message' => $read['error']]);
        }
        if ($read['entries'] === []) {
            return response()->json([
                'ok' => false,
                'message' => '貼り付けた中身から案件を読み取れませんでした。'
                    .'アサイン表の「日程」「コンテンツ」「顧客名」が入っているかたまりごと選んでコピーしてください。',
            ]);
        }

        // 複数貼られていても、この入口は先頭の1件だけを使う（案件登録は1件ぶんの画面のため）。
        $entry = $read['entries'][0];
        $get = $this->cellReader($entry['header'], $entry['row']);
        $info = $this->inspect($read['period'], $get, [], $entry);

        $attrs = $this->projectAttributes(
            $get, $info['name'], $info['date'], $info['count'],
            $info['client'], $info['meetTime'], OfficeScope::filterSingle($request) ?: '東京'
        );

        // 案件登録フォームの欄名 => 値。
        // ⚠ ここに載せた欄だけが埋まる。案件に欄を足したときは、必要ならここにも1行足す。
        $fields = [
            'start_date' => $info['date'],
            'client' => $attrs['client'] ?? '',
            'location' => $attrs['location'] ?? '',
            'start_time' => $attrs['start_time'] ?? '',
            'end_time' => $attrs['end_time'] ?? '',
            'event_enter_time' => $attrs['event_enter_time'] ?? '',
            'event_start_time' => $attrs['event_start_time'] ?? '',
            'event_end_time' => $attrs['event_end_time'] ?? '',
            'required_count' => $info['count'],
            'guest_count' => $attrs['guest_count'] ?? '',
            'team_count' => $attrs['team_count'] ?? '',
            'format' => $attrs['format'] ?? '',
            'scale' => $attrs['scale'] ?? '',
            'sales_owner' => $attrs['sales_owner'] ?? '',
            'operation_place' => $attrs['operation_place'] ?? '',
            'assembly_type' => $attrs['assembly_type'] ?? '',
            'catering' => $attrs['catering'] ?? '',
            'audio_equipment' => $attrs['audio_equipment'] ?? '',
            'transport' => $attrs['transport'] ?? '',
            'lodging' => $attrs['lodging'] ?? '',
            'staff_role' => $attrs['staff_role'] ?? '',
            'ops_sheet_url' => $attrs['ops_sheet_url'] ?? '',
            'note' => $attrs['note'] ?? '',
            // 名前の違う欄（DBの列名と画面の欄名が違うもの）。
            'content_names' => $info['name'],
            'addtl' => $attrs['category'] ?? '',
            'yomi' => $attrs['yomi'] ?? '',
            'multi' => ($attrs['is_multi'] ?? false) ? 'あり' : '',
        ];

        // 空のものは送らない（画面の既定値を空で上書きしないため）。
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');

        // 貼ったのに入らなかった項目は知らせる（黙って落とすと気づけない）。
        return response()->json([
            'ok' => true,
            'fields' => $fields,
            'people' => array_map(fn ($p) => $p['name'], $entry['people'] ?? []),
            'slots' => $entry['slots'] ?? 0,
            'unmapped' => $read['unmapped'],
            'errors' => $info['errors'],
            'more' => count($read['entries']) - 1,
        ]);
    }

    /**
     * 1件ぶんの中身を取り出して、入れられるかどうかを見る。取込と下見で同じ判定を使う。
     *
     * @return array{name:string, date:string, rawDate:string, count:string, client:?string,
     *               meetTime:?string, errors: list<string>}
     */
    private function inspect(?array $period, callable $get, array $edit = [], ?array $entry = null): array
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

        // 運営人数がシートに書かれていないときは「アサインされている人＋『メンバー』の空き枠」で埋める
        // （2026-08-27 baba選択）。名古屋のシートは運営人数の欄がほとんど空で、そのままだと全部0人になる。
        // ⚠ 画面で人数を空にした場合はそれも「直した」＝勝手に埋め直さない（$edit を見ている $pick の後）。
        if ($count === '' && ! array_key_exists('count', $edit) && $entry !== null && is_array($entry['people'] ?? null)) {
            $auto = count($entry['people']) + (int) ($entry['slots'] ?? 0);
            if ($auto > 0) {
                $count = (string) $auto;
            }
        }

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
            // ファイルを選ぶか、貼り付けるかのどちらか（2026-08-27 baba要望）。
            'csv' => ['required_without:paste', 'file', 'mimes:csv,txt'],
            'paste' => ['required_without:csv', 'nullable', 'string'],
            // 貼り付けのときだけ要る「何年何月ぶんか」（例 2026-09）。
            'period' => ['nullable', 'string'],
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
            $info = $this->inspect($read['period'], $get, $edit, $entry);

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
            // ファイルを選ぶか、貼り付けるかのどちらか（2026-08-27 baba要望）。
            'csv' => ['required_without:paste', 'file', 'mimes:csv,txt'],
            'paste' => ['required_without:csv', 'nullable', 'string'],
            // 貼り付けのときだけ要る「何年何月ぶんか」（例 2026-09）。
            'period' => ['nullable', 'string'],
            // どの拠点の案件として入れるか（画面で選ぶ）。未指定は自分の拠点。
            'office' => ['nullable', 'string'],
            // 画面の表で直した内容（JSON）。直していなければ空。
            'edits' => ['nullable', 'string'],
            // 終わった案件（過去）か、これからの案件か。
            'mode' => ['nullable', 'string'],
        ]);

        $office = $this->targetOffice($request);
        $mode = $this->targetMode($request);
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

        // 巻き取り・ヘルプの相手の拠点（画面で選ぶ）。空＝これまでどおり備考の文字だけ。
        $shareOffice = $this->shareOffice($request, $office);

        $created = 0;
        $updated = 0;
        $assignCount = 0;
        $shared = 0;
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
            $info = $this->inspect($period, $get, $edit, $entry);
            if ($info['errors']) {
                $errors[] = $entry['label']."（{$info['name']}）：".implode('／', $info['errors']);

                continue;
            }

            $name = $info['name'];
            $date = $info['date'];
            $client = $info['client'];
            $meetTime = $info['meetTime'];

            // この案件に入れるアサイン（氏名＋役割コード）。案件の状態を決めるのに人数を使うので先に作る。
            $assignments = $this->onePerPerson($entry['people'] === null
                ? $this->assignmentsFromColumns($get, $people, $missingNames, $ambiguousNames)
                : $this->assignmentsFromPeople($entry['people'], $people, $missingNames, $ambiguousNames, $unknownRoles));

            $attrs = $this->projectAttributes($get, $name, $date, $info['count'], $client, $meetTime,
                $office, $mode, $assignments !== []);

            // 同じ案件があるか＝開催日・コンテンツ名・顧客名・集合時間が全部同じ。
            // ⚠ 日付は「2026-01-20 00:00:00」の形で保存されるので、where ではなく whereDate で探す
            //   （where だと一致せず、取り込み直すたびに案件が増えてしまう。既知の罠）。
            $existing = Project::whereDate('start_date', $date)
                ->where('project_name', $name)
                ->where('client', $client)
                ->where('start_time', $meetTime)
                ->first();

            // アサインの状態＝これからの案件は「仮」（まだ動かせるように・2026-08-26 baba選択）。
            $assignStatus = $mode === self::MODE_FUTURE ? '仮' : '確定';

            // シートの「巻き取り／ヘルプ」の印（無ければ空文字）。
            $crossKind = $get('拠点間の関わり');

            $isFuture = $mode === self::MODE_FUTURE;

            DB::transaction(function () use ($existing, $attrs, $date, $assignments, $assignStatus, $isFuture,
                $crossKind, $shareOffice, $office, &$created, &$updated, &$assignCount, &$shared) {
                if ($existing) {
                    // ⚠ 公開の状態（staff_published）は、読み込み直しでは触らない（2026-08-28 baba報告）。
                    //   スタッフを1人足すためにアサイン表を読み込み直しただけで**公開が取り消され**、
                    //   募集がスタッフの画面から消えていた。公開の入口は公開ボードの1つだけ、
                    //   という決まりに合わせる（案件登録の上書き更新も同じく触っていない）。
                    //
                    // ⚠ ただし「過去」で入れてしまったものを「これから」で入れ直したときだけは、
                    //   非公開に戻す。過去あつかいは公開済みで入るので、そのままだと
                    //   **これからの案件のクライアント名・会場がスタッフ全員に見えたまま**になる。
                    //   見分け方＝過去で入った案件は「募集しない」かつ「確定」になっている。
                    $wasPastImport = ! (bool) $existing->is_recruiting && (string) $existing->status === '確定';
                    if (! ($isFuture && $wasPastImport)) {
                        unset($attrs['staff_published']);
                    }
                    $existing->fill($attrs)->save();
                    $project = $existing;
                    $updated++;
                } else {
                    $project = Project::create($attrs + ['id' => $this->nextProjectId($date)]);
                    $created++;
                }

                // 巻き取り・ヘルプを「拠点間の関わり」として記録する（2026-08-28 baba要望）。
                // ⚠ シートには「どの拠点から」が書かれていないので、画面で選んだ相手の拠点を使う。
                //   選んでいなければ、これまでどおり備考の文字だけ（勘で拠点を決めない）。
                if ($crossKind !== '' && $shareOffice !== '' && $shareOffice !== $office) {
                    $kind = str_contains($crossKind, '巻き取り') ? '巻き取り' : 'ヘルプ';
                    ProjectShare::updateOrCreate(
                        ['project_id' => $project->id, 'office' => $shareOffice],
                        ['kind' => $kind, 'created_by' => Auth::id()]
                    );
                    $shared++;
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
                        $row->update(['role' => $a['role'], 'status' => $assignStatus]
                            + AssignmentStamp::forUpdate($row, $assignStatus));
                    } else {
                        Assignment::create([
                            'project_id' => $project->id,
                            'staff_id' => $a['id'],
                            'date' => $date,
                            'role' => $a['role'],
                            'status' => $assignStatus,
                        ] + AssignmentStamp::forCreate($assignStatus));
                    }
                    $assignCount++;
                }
            });
        }

        return redirect('/past-import')
            ->with('status', $this->buildMessage($isMonthly, $created, $updated, $assignCount, $skipped, $errors, $unmapped, $mode, $shared))
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
     *
     * 通常の取込と同じ読み替えを使う。「過去」と「これから」で変わるのは末尾の4つだけ
     * （状態・公開・募集・確度）。読み取りは共通のまま。
     */
    private function projectAttributes(callable $get, string $name, string $date,
        string $count, ?string $client, ?string $meetTime, string $office,
        string $mode = self::MODE_PAST, bool $hasPeople = false): array
    {
        $guests = $this->digits($get('お客様人数'));
        $teams = $this->digits($get('チーム数'));
        $future = $mode === self::MODE_FUTURE;

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
            // 募集するか。過去案件はしない（スタッフ画面に「募集中」として出さないため）。
            // これからの案件は募集する＝ただしシートに「メンバー募集なし」と書いてあれば しない。
            // ⚠ 募集ONだけではスタッフに見えない（公開ボードで公開して初めて出る）。
            'is_recruiting' => $future && $get('スタッフ募集') !== '募集しない',
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
            'note' => $this->noteWithMarks($get),
            // キャンセルの案件は「イベント数に数えない」で入れる（実施していないため。2026-08-26 baba要望）。
            // ⚠ 案件の状態（status）は 未着手/調整中/確定/完了 の4つしか無いので、キャンセルは
            //   ここと備考で表す。数え方の正本は App\Support\EventCount（null＝自動／false＝数えない）。
            // 「営業案件」もイベント数に数えない（体験会・EXPO と同じ扱い。2026-08-27 baba）。
            'count_as_event' => ($get('キャンセル') !== '' || $get('イベント数') === '数えない') ? false : null,
            // 案件の状態。過去の実績＝確定。これから＝まだ動くので「調整中」（人が1人も
            // 入っていなければ「未着手」）＝アサインが必要な案件として日別ボードにも出る。
            'status' => $future ? ($hasPeople ? '調整中' : '未着手') : '確定',
            // スタッフに見せるか。過去の実績＝公開済み（本人が自分の履歴として見られるように）。
            // ⚠ これからの案件は**未公開**で入れる（2026-08-26 baba選択）。取り込んだ瞬間に
            //   クライアント名・会場までスタッフ全員に見えてしまうため。公開の入口は
            //   公開ボードの「公開する」1つだけ、という決まりを崩さない（2026-08-20）。
            'staff_published' => ! $future,
        ];
    }

    /**
     * 備考＝シートの備考に、月シートの「日程の上の印」を足したもの（2026-08-26 baba要望）。
     *
     * 【なぜ備考なのか】
     * ・キャンセル … 案件の状態（status）に「キャンセル」が無い。作るとアサイン画面の
     *   進み方（未着手→調整中→確定→公開）まで作り直しになるので、いまは備考＋
     *   「イベント数に数えない」で表す。
     * ・巻き取り／ヘルプ … 本来は拠点間共有（project_shares）に入れるものだが、
     *   **どの拠点から来たのかがシートに書かれていない**。勘で拠点を決めると拠点別の集計が
     *   狂うので、備考に書き残すだけにする。
     *
     * ⚠ 毎回シートから作り直すので、取り込み直しても同じ文字が二重に増えることはない。
     */
    private function noteWithMarks(callable $get): ?string
    {
        $marks = [];
        if ($get('キャンセル') !== '') {
            $marks[] = 'キャンセル';
        }
        if ($get('拠点間の関わり') !== '') {
            $marks[] = '他拠点から'.$get('拠点間の関わり');
        }
        // 「営業案件」＝イベント数に数えないもの。数えない理由が案件を見て分かるように残す
        // （実施形態が空で入るので、備考が無いと「入れ忘れ」に見える）。
        if ($get('イベント数') === '数えない') {
            $marks[] = '営業案件（イベント数に数えない）';
        }

        $note = $get('備考');
        $head = $marks ? '【'.implode('・', $marks).'】' : '';

        return ($head.$note) ?: null;
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
    /**
     * この表の扱い（過去／これから）。
     * ⚠ 知らない値が来たら「過去」にする＝今までの動きを既定にして、事故のときに
     *   「未公開で入って誰にも見えない」より「今までどおり」に倒す。
     */
    private function targetMode(Request $request): string
    {
        return trim((string) $request->input('mode', '')) === self::MODE_FUTURE
            ? self::MODE_FUTURE
            : self::MODE_PAST;
    }

    private function targetOffice(Request $request): string
    {
        $sent = trim((string) $request->input('office', ''));

        if ($sent !== '' && in_array($sent, OfficeScope::options(), true)) {
            return $sent;
        }

        return OfficeScope::filterSingle($request);
    }

    /**
     * 巻き取り・ヘルプの「相手の拠点」（2026-08-28 baba要望）。
     *
     * ⚠ シートには「巻き取り」「ヘルプ」としか書かれておらず、**どの拠点からかが書かれていない**。
     *   勘で決めると拠点別の集計が狂うので、取込の画面で選んでもらう。
     *   選んでいなければ空を返す＝これまでどおり備考に文字を残すだけにする。
     * ⚠ 取込先の拠点と同じものは受け付けない（自分に頼む印は作らない）。
     */
    private function shareOffice(Request $request, string $ownOffice): string
    {
        $sent = trim((string) $request->input('share_office', ''));

        if ($sent === '' || $sent === $ownOffice) {
            return '';
        }

        return in_array($sent, OfficeScope::options(), true) ? $sent : '';
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
        int $skipped, array $errors, array $unmapped, string $mode = self::MODE_PAST, int $shared = 0): string
    {
        $future = $mode === self::MODE_FUTURE;
        $msg = $isMonthly
            ? '月ごとのアサイン表として読みました。'
            : 'list形式（1案件＝1行）として読みました。';
        $msg .= ($future ? 'これからの案件' : '過去案件')."を{$created}件 新しく登録しました";
        if ($updated > 0) {
            $msg .= "（同じ案件だった{$updated}件は上書きしました）";
        }
        $msg .= '。アサインは'.$assignCount.'件を「'.($future ? '仮' : '確定').'」で入れました。';
        if ($future) {
            $msg .= ' この案件はまだ未公開です＝スタッフには見えていません。'
                .'公開ボードで人数を整えてから「公開する」を押してください。';
        }

        if ($shared > 0) {
            $msg .= " 巻き取り・ヘルプの印が付いていた{$shared}件に、拠点間の関わりを記録しました。";
        }

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
