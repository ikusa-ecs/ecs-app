<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Support\CsvText;
use Illuminate\Http\Request;

/**
 * CSV一括取込。サイドバーは「CSV一括取込」の1項目に集約し、
 * ハブ画面（/imports）から名簿・コンテンツ・案件の各取込へ入る。
 *
 * 各取込は名簿取込（PersonImportController）と同じ作りの型：
 * BOM除去 → 改行で分割 → str_getcsv（第4引数=エスケープ無効・PHP8.4対応）→
 * 見出しを「列名→位置」表にして値を引く → 行ごとに必須チェック → OK行だけ登録。
 *
 * ・コンテンツ … コンテンツ名／分類／体力系／紙が必要／1チーム枚数／利用中。IDは CT-### を自動採番。
 * ※拠点はそう増えないため CSV取込は設けず、共通設定のマスタ管理で手入力する。
 */
class MasterImportController extends Controller
{
    /** CSV取込のハブ画面（名簿・コンテンツ・案件の入口をまとめる）。 */
    public function hub()
    {
        return view('imports');
    }

    // ── コンテンツ ──────────────────────────────────────────

    /** コンテンツ取込画面。 */
    public function showContent()
    {
        return view('content_import');
    }

    /** 記入済みCSVを読んで contents に複数登録する。 */
    public function importContent(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        [$lines, $error] = $this->readCsv($request);
        if ($error) {
            return redirect('/content-import')->with('import_error', $error);
        }

        // 見出し行 → 列名→位置 の対応表。
        $get = $this->columnGetter(array_shift($lines));

        // 同名チェックの元（既存＋このファイル内で出たもの）。
        $existingNames = Content::pluck('content_name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();
        $seenNames = [];

        $nextNum = $this->maxContentIdNum();

        $okCount = 0;
        $errors = [];
        $lineNo = 1;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $lineNo++;
            $row = str_getcsv($line, ',', '"', '');

            $name = $get($row, 'コンテンツ名');
            $category = $get($row, '分類');
            $physical = $this->truthy($get($row, '体力系'));
            $paper = $this->truthy($get($row, '紙が必要'));
            $sheets = $get($row, '1チーム枚数');
            $activeRaw = $get($row, '利用中');

            // --- 入力チェック ---
            $rowErrors = [];
            if ($name === '') {
                $rowErrors[] = 'コンテンツ名が空です';
            } else {
                $lower = mb_strtolower($name);
                if (in_array($lower, $existingNames, true) || in_array($lower, $seenNames, true)) {
                    $rowErrors[] = '同名のコンテンツが既にあります（重複）';
                }
            }
            if ($sheets !== '' && (! ctype_digit($sheets) || (int) $sheets < 1)) {
                $rowErrors[] = '1チーム枚数は1以上の数字で入力してください';
            }
            if ($rowErrors) {
                $errors[] = "{$lineNo}行目（{$name}）：" . implode('／', $rowErrors);
                continue;
            }

            // --- OK行を登録 ---
            $nextNum++;
            Content::create([
                'id' => 'CT-' . str_pad((string) $nextNum, 3, '0', STR_PAD_LEFT),
                'content_name' => $name,
                'category' => $category ?: null,
                'is_physical' => $physical,
                'needs_paper' => $paper,
                'sheets_per_team' => $sheets !== '' ? (int) $sheets : 1,
                // 利用中は空欄なら「利用中(true)」を既定にする（新規登録は使う前提）。
                'active' => $activeRaw === '' ? true : $this->truthy($activeRaw),
            ]);
            $seenNames[] = mb_strtolower($name);
            $okCount++;
        }

        return redirect('/content-import')
            ->with('status', $this->summary($okCount, 'コンテンツ', $errors));
    }

    // ── 共通ヘルパー ────────────────────────────────────────

    /**
     * アップロードCSVを行配列にする（BOM除去・CRLF対応）。
     *
     * @return array{0: array<int,string>, 1: ?string}  [行配列, エラー文（無ければnull）]
     */
    private function readCsv(Request $request): array
    {
        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        // BOM除去＋文字コードをUTF-8にそろえる（ExcelがShift_JISで保存したCSVもここで読める）。
        $raw = CsvText::toUtf8($raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        if (count($lines) < 2) {
            return [[], 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。'];
        }

        return [$lines, null];
    }

    /** 見出し行から「列名→値」を引く関数を作る（前後の空白は無視）。 */
    private function columnGetter(string $headerLine): callable
    {
        $header = str_getcsv($headerLine, ',', '"', '');
        $col = array_flip(array_map('trim', $header));

        return function (array $row, string $name) use ($col) {
            $i = $col[$name] ?? null;

            return ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
        };
    }

    /** CSVの文字を ○/はい/1 などの「はい」表記として真偽に変換する。 */
    private function truthy(string $value): bool
    {
        $v = mb_strtolower(trim($value));

        return in_array($v, ['○', '◯', '✓', '1', 'はい', '有', 'yes', 'true', 'on', '利用中'], true);
    }

    /** 「CT-###」の既存IDのうち最大の番号。無ければ0。 */
    private function maxContentIdNum(): int
    {
        return (int) (Content::where('id', 'like', 'CT-%')
            ->pluck('id')
            ->map(fn ($id) => (int) substr($id, 3))
            ->max() ?? 0);
    }

    /** 登録結果のメッセージを組み立てる。 */
    private function summary(int $okCount, string $label, array $errors): string
    {
        $msg = "CSVから{$label}を{$okCount}件登録しました。";
        if ($errors) {
            $msg .= ' エラー' . count($errors) . '件は登録しませんでした：' . implode(' / ', $errors);
        }

        return $msg;
    }
}
