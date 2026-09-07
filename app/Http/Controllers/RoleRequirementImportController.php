<?php

namespace App\Http\Controllers;

use App\Support\RoleRequirementCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 必要アサイン人数の取込（/role-requirement-import・2026-09-07 baba要望）。
 *
 * 【なぜ要るか】
 * 「必要アサイン人数リスト」（コンテンツごと・規模ごとに何のポジションが何人か）は、
 * これまで **artisan コマンドでしか入れられなかった**。つまりリストが更新されるたびに
 * 開発側の作業が必要で、小沼さんご自身では反映できなかった。名簿やコンテンツと同じように
 * 画面から入れられるようにする。
 *
 * 【安全のためにしていること】
 * ・**必ずプレビューを見せてから保存する**（どのコンテンツに何人ぶん入るかを見てから確定）。
 * ・プレビューで読んだファイルを**そのまま一時保存**して確定に使う
 *   ＝ファイルを選び直させない＝**見たものと入るものが必ず同じ**になる。
 * ・**CSVに無いコンテンツは触らない。** CSVにあるコンテンツだけ、必要人数を入れ替える。
 * ・コンテンツ台帳に無い商品名は**新しく作る**ので、プレビューで「★新規」と出して先に見せる。
 * ・**名前の書き方だけ違うものは同じコンテンツとみなす**（2026-09-07 baba報告
 *   「案件名がちょっとちがうだけで、登録済みのやつも新規登録されちゃう」）。
 *   全角/半角・空白・「・」・大文字小文字の違いは同じものとして拾い、それでも決められないものは
 *   **プレビューで人に選ばせる**（勘で寄せない・黙って台帳を増やさない）。見比べ方の正本＝
 *   App\Support\RoleRequirementCsv::normalizeName / matchProducts。
 * ・貼り付け欄は置かない。このCSVは**セルの中に改行が入っている**（コンテンツ名の箇条書き）ため、
 *   コピー＆貼り付けでは形が崩れて読めない。ファイルを選ぶ形だけにする。
 */
class RoleRequirementImportController extends Controller
{
    /** 一時保存の置き場所（プレビュー→確定の間だけ置く）。 */
    private const TEMP_DIR = 'imports/role-requirements';

    public function show()
    {
        return view('role_requirement_import');
    }

    /** 取り込む前に、何がどう入るかを見せる。 */
    public function preview(Request $request)
    {
        $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt'],
        ], [], ['csv' => 'CSVファイル']);

        $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        $parsed = RoleRequirementCsv::parse(RoleRequirementCsv::rows($raw));

        if ($parsed === []) {
            return back()->with('import_error',
                'このCSVからは必要アサイン人数を読み取れませんでした。'
                .'「必要アサイン人数リスト」のシートをそのままCSVで書き出したファイルを選んでください'
                .'（見出しが「NO／名前／P／巡回／備考／サイズ」の並びになっているものです）。');
        }

        // 確定でファイルを選び直さずに済むよう、読んだファイルをそのまま置いておく。
        $this->tidyOldFiles();
        $token = Str::random(32);
        Storage::put(self::TEMP_DIR."/{$token}.csv", $raw);

        $summary = RoleRequirementCsv::summary($parsed);

        return view('role_requirement_import', [
            'summary'    => $summary,
            'token'      => $token,
            'fileName'   => $request->file('csv')->getClientOriginalName(),
            'collisions' => $this->collisions($summary),
            'chosen'     => [],
        ]);
    }

    /** プレビューの内容で保存する。 */
    public function import(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/'],
            'map'   => ['nullable', 'array'],
            'map.*' => ['nullable', 'string', 'max:50'],
        ]);

        $path = self::TEMP_DIR.'/'.$request->input('token').'.csv';
        if (! Storage::exists($path)) {
            // 置いた覚えのないトークン＝時間が経って片づけられたか、画面を開き直したとき。
            return redirect('/role-requirement-import')->with('import_error',
                '確認した内容が見つかりませんでした（時間が経ちすぎたようです）。もう一度ファイルを選んで確認してください。');
        }

        // 画面で選んでもらった「どの台帳に入れるか」。'' は「新規で作る」。
        $overrides = array_map(fn ($v) => (string) $v, (array) $request->input('map', []));

        $parsed = RoleRequirementCsv::parse(RoleRequirementCsv::rows((string) Storage::get($path)));
        $summary = RoleRequirementCsv::summary($parsed, $overrides);

        // ⚠ 同じ台帳のコンテンツを2つの行が指していたら、取り込まずに直してもらう。
        //   そのまま入れると、あとの行の人数だけが残って前の行が消えたように見える。
        $collisions = $this->collisions($summary);
        if ($collisions !== []) {
            return view('role_requirement_import', [
                'summary'    => $summary,
                'token'      => $request->input('token'),
                'fileName'   => '選び直してください',
                'collisions' => $collisions,
                'chosen'     => $overrides,
            ]);
        }

        $result = RoleRequirementCsv::apply($parsed, $overrides);
        Storage::delete($path);

        $message = "必要アサイン人数を取り込みました（コンテンツ {$result['contents']}件 / 枠 {$result['slots']}件）。";
        if ($summary['matchedCount'] > 0) {
            $message .= " うち {$summary['matchedCount']}件は、名前の書き方が違うだけの登録済みコンテンツに入れました。";
        }
        if ($result['new'] > 0) {
            $message .= " コンテンツ台帳に {$result['new']}件を新しく登録しました。";
        }

        return redirect('/role-requirement-import')->with('status', $message);
    }

    /**
     * 「CSVの2つ以上の行が、台帳の同じコンテンツを指している」状態を見つける。
     *
     * @return array<string, list<string>>  コンテンツID => その先を指している商品名
     */
    private function collisions(array $summary): array
    {
        $byContent = [];
        foreach ($summary['items'] as $item) {
            if ($item['contentId'] !== null) {
                $byContent[$item['contentId']][] = $item['product'];
            }
        }

        return array_filter($byContent, fn ($products) => count($products) > 1);
    }

    /**
     * 一時ファイルの片づけ。確定まで進まなかったぶんが残り続けないよう、
     * プレビューのたびに1日より古いものを消す（中身はCSVの写しなので消して困らない）。
     */
    private function tidyOldFiles(): void
    {
        $limit = now()->subDay()->getTimestamp();
        foreach (Storage::files(self::TEMP_DIR) as $file) {
            if (Storage::lastModified($file) < $limit) {
                Storage::delete($file);
            }
        }
    }
}
