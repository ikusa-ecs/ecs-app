<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Support\AssignmentRole;
use App\Support\AssignmentStamp;
use App\Support\ClientName;
use App\Support\CsvText;
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
        return view('past_import', [
            // 名簿の氏名。画面でCSVを選んだ瞬間に「この人は名簿にいる／いない」を見せるために渡す
            // （登録してから初めて分かる、では直すのに時間がかかるため）。
            // 同姓同名も分かるよう、そのまま重複ありで渡す。
            'rosterNames' => \App\Models\Person::pluck('name')->filter()->values(),
        ]);
    }

    /** CSVを読んで、過去案件とそのアサインを登録する。 */
    public function import(Request $request)
    {
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt']]);

        // 文字コードをUTF-8にそろえる（Excel保存のShift_JISでも読めるように）。
        $raw = CsvText::toUtf8((string) file_get_contents($request->file('csv')->getRealPath()));
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        if (count($lines) < 2) {
            return redirect('/past-import')
                ->with('import_error', 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $resolved = ProjectImportColumns::resolve($header);
        $col = $resolved['map'];
        $unmapped = $resolved['unmapped'];

        $get = function (array $row, string $name) use ($col) {
            $i = $col[$name] ?? null;
            $value = ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
            if ($value === '') {
                return '';
            }
            if (in_array($name, ProjectImportColumns::TIME_COLUMNS, true)) {
                return ProjectImportColumns::normalizeTime($value) ?: $value;
            }

            return $value;
        };

        // 名簿の索引は1回だけ作って使い回す（行ごとに引くと重いため）。
        $people = PersonLookup::index();

        $created = 0;
        $updated = 0;
        $assignCount = 0;
        $errors = [];
        $missingNames = [];     // 名簿に無かった人（あとで一覧にする）
        $ambiguousNames = [];   // 同姓同名で決められなかった人
        $lineNo = 1;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $lineNo++;
            $row = str_getcsv($line, ',', '"', '');

            $name = $get($row, '案件名');
            $rawDate = $get($row, '開催日');
            $date = ProjectImportColumns::normalizeDate($rawDate);
            $count = $get($row, '運営人数');

            // 必須は「案件名」と「開催日」の2つ。
            // ⚠ 運営人数は、これからの案件では必須だが過去案件では空のことがあるので必須にしない
            //   （実績を入れるのが目的なので、人数が分からない過去案件を弾いてしまうと入らない）。
            $rowErrors = [];
            if ($name === '') {
                $rowErrors[] = 'コンテンツ（案件名）が空です';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! $this->isRealDate($date)) {
                $rowErrors[] = $rawDate !== ''
                    ? "日程が読めません（{$rawDate}）。年から入れてください（例 2026-07-20）"
                    : '日程が空です（例 2026-07-20）';
            }
            if ($count !== '' && (! ctype_digit($count) || (int) $count < 0)) {
                $rowErrors[] = '運営人数は数字で入れてください';
            }
            if ($rowErrors) {
                $errors[] = "{$lineNo}行目（{$name}）：".implode('／', $rowErrors);
                continue;
            }

            $client = ClientName::normalize($get($row, 'クライアント'));
            $meetTime = $get($row, '集合時間') ?: null;

            $attrs = $this->projectAttributes($row, $get, $name, $date, $count, $client, $meetTime);

            // 同じ案件があるか＝開催日・コンテンツ名・顧客名・集合時間が全部同じ。
            // ⚠ 日付は「2026-01-20 00:00:00」の形で保存されるので、where ではなく whereDate で探す
            //   （where だと一致せず、取り込み直すたびに案件が増えてしまう。既知の罠）。
            $existing = Project::whereDate('start_date', $date)
                ->where('project_name', $name)
                ->where('client', $client)
                ->where('start_time', $meetTime)
                ->first();

            DB::transaction(function () use ($existing, $attrs, $date, $row, $get, $people,
                &$created, &$updated, &$assignCount, &$missingNames, &$ambiguousNames, &$errors, $lineNo, $name) {
                if ($existing) {
                    $existing->fill($attrs)->save();
                    $project = $existing;
                    $updated++;
                } else {
                    $project = Project::create($attrs + ['id' => $this->nextProjectId($date)]);
                    $created++;
                }

                // ── アサイン（D／MC／OP／スタッフ）──
                // その案件のこの取込ぶんは作り直す（取り込み直しで二重にならないように）。
                // ⚠ 消すのは「この取込が作る役割」だけ。他の画面で入れた役割は消さない。
                Assignment::where('project_id', $project->id)
                    ->whereIn('role', array_values(ProjectImportColumns::ASSIGN_COLUMNS))
                    ->delete();

                foreach (ProjectImportColumns::ASSIGN_COLUMNS as $column => $role) {
                    $cell = $get($row, $column);
                    if ($cell === '') {
                        continue;
                    }
                    $found = PersonLookup::resolveNames($cell, $people);

                    foreach ($found['missing'] as $n) {
                        $missingNames[$n] = true;
                    }
                    foreach ($found['ambiguous'] as $n) {
                        $ambiguousNames[$n] = true;
                    }

                    foreach ($found['ids'] as $staffId) {
                        // 同じ案件×同じ人×同じ日は1行（unique）。取り違え防止でIDで入れる。
                        // 「誰がいつ確定したか」の記録は AssignmentStamp が正本（2026-08-20）。
                        Assignment::updateOrCreate(
                            ['project_id' => $project->id, 'staff_id' => $staffId, 'date' => $date],
                            ['role' => $role, 'status' => '確定'] + AssignmentStamp::forCreate('確定')
                        );
                        $assignCount++;
                    }
                }
            });
        }

        return redirect('/past-import')
            ->with('status', $this->buildMessage($created, $updated, $assignCount, $errors, $unmapped))
            ->with('past_missing', array_keys($missingNames))
            ->with('past_ambiguous', array_keys($ambiguousNames));
    }

    /**
     * 1行 → projects に入れる値。
     * 通常の取込と同じ読み替えを使うが、状態は「確定」・公開済みにする（過去の実績なので）。
     */
    private function projectAttributes(array $row, callable $get, string $name, string $date,
        string $count, ?string $client, ?string $meetTime): array
    {
        $guests = $get($row, 'お客様人数');
        $teams = $get($row, 'チーム数');

        return [
            'project_name' => $name,
            'content_ids' => $this->resolveContentIds($name),
            'content_names' => [$name],
            'category' => $get($row, '区分') ?: null,
            'yomi' => $get($row, '確度') ?: '確定',
            'scale' => $get($row, '案件規模') ?: null,
            // 過去案件なので募集はしない（スタッフ画面に「募集中」として出さないため）。
            'is_recruiting' => false,
            'is_multi' => $get($row, '複数案件') === 'あり',
            'date_type' => $get($row, '日程種別') ?: '本番',
            'sales_owners' => $get($row, '営業担当') ? [$get($row, '営業担当')] : null,
            'format' => $get($row, '実施形態') ?: null,
            'online_tool' => $get($row, 'オンラインツール') ?: null,
            'broadcast' => $get($row, '配信種別') ?: null,
            'operation_place' => $get($row, '運営場所') ?: null,
            'client' => $client,
            'agency' => $get($row, '代理店名') ?: null,
            'staff_role' => $get($row, '担当体制') ?: null,
            'start_date' => $date,
            'start_time' => $meetTime,
            'end_time' => $get($row, '解散時間') ?: null,
            'event_enter_time' => $get($row, 'イベント入場') ?: null,
            'event_start_time' => $get($row, 'イベント開始') ?: null,
            'event_end_time' => $get($row, 'イベント終了') ?: null,
            'location' => $get($row, '会場住所') ?: null,
            'is_outdoor' => $get($row, '屋内外') ? ($get($row, '屋内外') === '屋外') : null,
            'lodging' => $get($row, '宿泊') ?: null,
            'assembly_type' => $get($row, '集合形式') ?: null,
            'required_count' => $count !== '' ? (int) $count : null,
            'guest_count' => ctype_digit($guests) ? (int) $guests : null,
            'team_count' => ctype_digit($teams) ? (int) $teams : null,
            'is_repeat' => $get($row, 'リピート') === 'あり',
            'alcohol' => $get($row, 'お酒') ? ($get($row, 'お酒') === 'あり') : null,
            'catering' => $get($row, 'ケータリング') ?: null,
            'audio_equipment' => $get($row, '音響機材') ?: null,
            'transport' => $get($row, '移動車両') ?: null,
            'pub_logo' => $get($row, 'ロゴ') ?: null,
            'pub_camera' => $get($row, 'カメラ') ?: null,
            'pub_article' => $get($row, '事例記事') ?: null,
            'pub_video' => $get($row, '動画') ?: null,
            'goods_owner_id' => $this->personIdByName($get($row, '物品担当')),
            'ops_sheet_url' => $get($row, '運営シートURL') ?: null,
            'prep_line_created' => $this->isDone($get($row, '準備:LINE作成')),
            'prep_line_sent' => $this->isDone($get($row, '準備:LINE概要送付')),
            'prep_line_double_check' => $this->isDone($get($row, '準備:LINEダブルチェック')),
            'prep_handover' => $this->isDone($get($row, '準備:引き継ぎ')),
            'prep_script' => $this->isDone($get($row, '準備:台本')),
            'note' => $get($row, '備考') ?: null,
            // 過去の実績＝確定・公開済み（本人が自分の履歴として見られるように）。
            'status' => '確定',
            'staff_published' => true,
        ];
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
    private function buildMessage(int $created, int $updated, int $assignCount, array $errors, array $unmapped): string
    {
        $msg = "過去案件を{$created}件 新しく登録しました";
        if ($updated > 0) {
            $msg .= "（同じ案件だった{$updated}件は上書きしました）";
        }
        $msg .= "。アサインは{$assignCount}件を「確定」で入れました。";

        if ($errors) {
            $msg .= ' エラー'.count($errors).'件は取り込みませんでした：'.implode(' / ', $errors);
        }
        if ($unmapped) {
            $msg .= ' ※ 取り込まなかった列：'.implode('・', $unmapped)
                .'（ECSに対応する項目がありません。必要なら教えてください）';
        }

        return $msg;
    }
}
