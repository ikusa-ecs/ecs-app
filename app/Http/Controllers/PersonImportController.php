<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Departments;
use App\Support\TempPassword;
use App\Models\StaffRoleEligibility;
use App\Support\AssignmentRole;
use App\Support\CsvText;
use Illuminate\Http\Request;

/**
 * 名簿（people）のCSV一括取込。
 *
 * 既存の案件取込（ProjectController@import）と同じ作りの型：
 * BOM除去 → 改行で分割 → str_getcsv（第4引数=エスケープ無効・PHP8.4対応）→
 * 見出しを「列名→位置」表にして値を引く → 行ごとに必須チェック → OK行だけ登録。
 *
 * 列：種別／氏名／ふりがな／メール／チャットワークID／事務所／所属／入社日／通算経験回数／できるポジション（任意）。
 *   所属は兼務があるので「／」「,」区切りで複数書ける（先頭＝主な所属）。
 * ⚠ 列を足すときは、画面のプレビュー（resources/views/person_import.blade.php の PI_HEADERS と
 *   piParse）も必ず一緒に直す。片方だけ直すと「プレビューでは読めない／登録では入らない」になる。
 * 自動：社員番号（E-###/S-###）・権限（種別から）・在籍（active=true）。
 * ※パスワードは入れない（アカウント発行は別作業）。
 * ※「できるポジション」はスタッフのみ対象。役割の正本は App\Support\AssignmentRole。
 */
class PersonImportController extends Controller
{
    /** 取込画面。 */
    public function show()
    {
        return view('person_import');
    }

    /** 記入済みCSVを読んで people に複数登録する。 */
    public function import(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
            // 「ログインアカウントも一緒に作る」チェック（任意）。
            'make_accounts' => ['nullable'],
        ]);

        // アカウントも作るか。作る場合は仮パスワードを自動生成し、初回ログインで
        // 本人にパスワード変更＋ふりがな・入社年月日を入れてもらう（must_onboard=true）。
        // ⚠ 取込だけだとパスワードが無く、名簿に載るのにログインできない状態になる
        //   （初回設定にも入らないので、ふりがな・入社日を本人に入れてもらえない）。
        $makeAccounts = $request->boolean('make_accounts');
        $issued = [];        // 発行できた人（画面に一覧＋CSVで渡す）
        $noEmail = [];       // メールが無くて発行できなかった人

        // CSV を行配列にする（BOM除去・文字コードをUTF-8にそろえる・CRLF対応）。
        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        $raw = CsvText::toUtf8($raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));

        if (count($lines) < 2) {
            return redirect('/person-import')
                ->with('import_error', 'CSVにデータ行がありません（1行目の見出しのみ、または空です）。');
        }

        // 見出し行 → 列名→位置 の対応表。
        $header = str_getcsv(array_shift($lines), ',', '"', '');
        $col = array_flip(array_map('trim', $header));
        $get = function (array $row, string $name) use ($col) {
            $i = $col[$name] ?? null;
            return ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
        };

        // 「できるポジション」列（任意）。別名の見出しも許容する。無ければ null＝この列はスキップ。
        $posColName = null;
        foreach (['できるポジション', 'できる役割', 'ポジション', '可能ポジション'] as $cand) {
            if (isset($col[$cand])) {
                $posColName = $cand;
                break;
            }
        }

        // メール重複チェックの元（既存＋このファイル内で出たもの）。
        $existingEmails = Person::whereNotNull('email')
            ->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $seenEmails = [];

        // 採番の元（種別ごとの現在の最大番号）。
        $empMax = $this->maxIdNum('E-');
        $staffMax = $this->maxIdNum('S-');

        $okCount = 0;
        $errors = [];
        $lineNo = 1;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $lineNo++;
            $row = str_getcsv($line, ',', '"', '');

            $type = $get($row, '種別');
            $name = $get($row, '氏名');
            // ふりがな（任意）。五十音順の並びに使う。見出しの別名も許す。
            $kana = $get($row, 'ふりがな') ?: ($get($row, 'よみ') ?: $get($row, '読み'));
            $email = $get($row, 'メール');
            $office = $get($row, '事務所');
            $dept = $get($row, '所属');
            $hire = $get($row, '入社日');
            $expc = $get($row, '通算経験回数');
            // チャットワークID（任意）。リマインドの宛先に使う。
            $cwid = $get($row, 'チャットワークID') ?: $get($row, 'CWID');

            // --- 入力チェック（JSの validate と同じ基準）---
            $rowErrors = [];
            $role = $type === '社員' ? 'employee' : ($type === 'スタッフ' ? 'staff' : null);
            if ($role === null) {
                $rowErrors[] = '種別は「社員」か「スタッフ」で入力してください';
            }
            if ($name === '') {
                $rowErrors[] = '氏名が空です';
            }
            if ($email !== '') {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = 'メールの形式が不正です';
                } else {
                    $lower = mb_strtolower($email);
                    if (in_array($lower, $existingEmails, true) || in_array($lower, $seenEmails, true)) {
                        $rowErrors[] = 'メールが既に使われています（重複）';
                    }
                }
            }
            if ($hire !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire) || ! $this->isRealDate($hire))) {
                $rowErrors[] = '入社日の形式が不正です（例 2025-04-01）';
            }
            if ($expc !== '' && ! ctype_digit($expc)) {
                $rowErrors[] = '通算経験回数は数字で入力してください';
            }
            if ($cwid !== '' && ! ctype_digit($cwid)) {
                $rowErrors[] = 'チャットワークIDは数字で入力してください';
            }
            // テンプレートの見本行が消し忘れたまま取り込まれる事故を防ぐ（2026-08-24 実際に発生）。
            // 見本のメールは example.com。本物の連絡先で example.com は使わないので、これで弾ける。
            // ⚠ 実際に起きたこと：見本行を消さずに取り込み、社長のふりがなが
            //   見本の「やまだ はなこ」になった（本人以外は直せない状態だった）。
            if ($email !== '' && preg_match('/@example\.(com|org|net)$/i', $email)) {
                $rowErrors[] = 'メールが見本のまま（@example.com）です。テンプレートの見本行を消してから取り込んでください';
            }
            if ($rowErrors) {
                $errors[] = "{$lineNo}行目（{$name}）：" . implode('／', $rowErrors);
                continue;
            }

            // --- OK行を登録 ---
            if ($role === 'employee') {
                $empMax++;
                $id = 'E-' . str_pad((string) $empMax, 3, '0', STR_PAD_LEFT);
            } else {
                $staffMax++;
                $id = 'S-' . str_pad((string) $staffMax, 3, '0', STR_PAD_LEFT);
            }

            $attrs = [
                'id' => $id,
                'role' => $role,
                'name' => $name,
                'name_kana' => $kana ?: null,
                'email' => $email ?: null,
                'chatwork_id' => $cwid ?: null,
                'permission' => $role === 'employee' ? 'employee' : 'staff',
                'office' => $office ?: null,
                'hire_date' => $hire ?: null,
                'active' => true,
            ];
            if ($role === 'employee') {
                // 所属。兼務があるので「／」か「,」で区切って複数書ける（先頭＝主な所属）。
                // 選択肢と整え方の正本＝App\Support\Departments。
                // 色分け・集計はイベプラ/セールス/クリエイティブ以外を「その他」にまとめて扱う。
                $deptList = preg_split('/[\/／,、･・]+/u', $dept) ?: [];
                $deptList = array_values(array_filter(array_map('trim', $deptList), fn ($d) => $d !== ''));
                $attrs['department'] = $deptList[0] ?? null;                      // 主な所属＝先頭
                $attrs['departments'] = Departments::normalize($deptList[0] ?? null, $deptList);
            } else {
                $attrs['experience_count'] = $expc !== '' ? (int) $expc : null;
            }

            // ログインアカウントも作る場合＝仮パスワードを付けて、初回設定へ誘導する。
            // メールが無い人はログインできないので、アカウントは作らずに知らせる。
            if ($makeAccounts) {
                if ($email !== '') {
                    $tempPassword = TempPassword::make();
                    $attrs['password'] = $tempPassword;      // モデルのキャストで自動ハッシュ化
                    $attrs['must_onboard'] = true;           // 初回ログインで初期設定へ
                } else {
                    $noEmail[] = $name;
                }
            }

            $person = Person::create($attrs);

            if ($makeAccounts && $email !== '') {
                $issued[] = [
                    'id' => $person->id,
                    'name' => $name,
                    'email' => $email,
                    'password' => $tempPassword,
                ];
            }

            // できるポジション（任意・スタッフのみ）：セルの各トークンを正規コードに直して入れ直す。
            if ($role === 'staff' && $posColName !== null) {
                $posCell = $get($row, $posColName);
                if ($posCell !== '') {
                    $codes = [];
                    // カンマ／スラッシュ／読点（全角・半角どちらも）で区切る。中黒（・）は区切りにしない
                    //（「軍師・サポーター」を1語として扱うため）。
                    foreach (preg_split('/[,\/、，／]+/u', $posCell) as $token) {
                        $code = $this->resolvePosition((string) $token);
                        if ($code !== null) {
                            $codes[] = $code;
                        }
                    }
                    $codes = array_values(array_unique($codes));

                    // その人ぶんを一旦消して入れ直す（重複行を作らない）。
                    StaffRoleEligibility::where('staff_id', $person->id)->delete();
                    foreach ($codes as $code) {
                        StaffRoleEligibility::create(['staff_id' => $person->id, 'position' => $code]);
                    }
                }
            }

            if ($email !== '') {
                $seenEmails[] = mb_strtolower($email);
            }
            $okCount++;
        }

        $msg = "CSVから{$okCount}名を名簿に登録しました。";
        if ($makeAccounts) {
            $msg .= ' そのうち' . count($issued) . '名にログインアカウント（仮パスワード）を発行しました。';
        }
        if ($noEmail) {
            $msg .= ' メール未記入のため発行できなかった方：' . implode('・', $noEmail)
                . '（名簿には登録済みです。メールを入れてから「アカウント発行」で個別に発行してください）';
        }
        if ($errors) {
            $msg .= ' エラー' . count($errors) . '件は登録しませんでした：' . implode(' / ', $errors);
        }

        return redirect('/person-import')
            ->with('status', $msg)
            // 発行一覧は「その場で1回だけ見せる」もの（サーバーには残さない）。
            // 平文の仮パスワードなので、配り終わったら閉じてもらう前提。
            ->with('issued', $issued);
    }

    /** 指定プレフィックス（E-/S-）の既存IDの最大番号。無ければ0。 */
    private function maxIdNum(string $prefix): int
    {
        return (int) (Person::where('id', 'like', $prefix . '%')->get()
            ->map(fn ($p) => (int) preg_replace('/\D/', '', $p->id))
            ->max() ?? 0);
    }

    /**
     * 「できるポジション」の1トークンを正規コード（D/OP/MC/FC/CK/SP/RP）に直す。
     * コードそのもの（OP等）・日本語ラベル（音響・軍師・受付等）・旧コード（GUN/UKE）を受ける。
     * 解決できなければ null（＝その語は無視）。SD は対象外（スタッフの可否では使わない）。
     */
    private function resolvePosition(string $token): ?string
    {
        // 前後の空白と、途中の全角・半角スペースを除去（照合をぶれさせないため）。
        $t = preg_replace('/[\s\x{3000}]+/u', '', trim($token));
        if ($t === '') {
            return null;
        }

        // まずコードとして直接照合（英字は大文字化）。旧コードは読み替える。
        $upper = mb_strtoupper($t);
        $upper = ['GUN' => 'SP', 'UKE' => 'RP'][$upper] ?? $upper;
        if (in_array($upper, AssignmentRole::POSITIONS, true)) {
            return $upper;
        }

        // 日本語ラベル・別名から照合。
        $aliases = [
            'ディレクター' => 'D', 'D（ディレクター）' => 'D',
            '音響' => 'OP', 'オペレーター' => 'OP', 'OP（音響）' => 'OP',
            '司会' => 'MC', '司会進行' => 'MC', 'MC（司会進行）' => 'MC',
            '巡回' => 'FC', 'ファシリ' => 'FC', '巡回ファシリ' => 'FC', 'FC（巡回ファシリ）' => 'FC',
            'チェッカー' => 'CK', 'CK（チェッカー）' => 'CK',
            '軍師' => 'SP', 'サポーター' => 'SP', '軍師・サポーター' => 'SP',
            '受付' => 'RP',
        ];

        return $aliases[$t] ?? null;
    }

    /** YYYY-MM-DD が実在する日付か。 */
    private function isRealDate(string $date): bool
    {
        [$y, $m, $d] = array_pad(explode('-', $date), 3, '0');

        return checkdate((int) $m, (int) $d, (int) $y);
    }
}
