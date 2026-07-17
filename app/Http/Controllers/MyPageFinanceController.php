<?php

namespace App\Http\Controllers;

use App\Models\ProjectFinance;
use App\Support\PersonalCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * マイページ・収支入力（/mypage-finance）。
 *
 * これまで画面は cases.js（見本）＋Blade内ベタ書きの MY_ASSIGN で動いていた。
 * ここでは マイページと同じ共通部品（PersonalCases）で
 *   ・自分＝誰か   ・全案件（DB）   ・自分のアサイン（案件ID→役割コード）
 * を渡し、収支の対象案件を本物のデータで出す。
 *
 * 収支の対象＝自分が「D（ディレクター）」の案件、または「営業担当」の案件。
 *
 * 収支の入力（売上・経費明細・メモ）は project_finances テーブルへ本物保存する。
 * 画面を開き直すと、前回入力した値が復元される（ブラウザ記憶ではなく共有DBが正）。
 */
class MyPageFinanceController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $me = PersonalCases::meModel();

        $cases = PersonalCases::cases($today);

        // 対象案件ぶんの「保存済み収支」を案件ID→{売上・明細・メモ} の形で用意する。
        // 画面はこれを使って、案件を選んだときに前回入力を各欄へ復元する。
        $finances = ProjectFinance::whereIn('project_id', $cases->pluck('id'))
            ->get()
            ->keyBy('project_id')
            ->map(fn (ProjectFinance $f) => [
                'revenue' => $f->revenue,
                'items' => $f->items ?? [],
                'memo' => $f->memo ?? '',
            ]);

        return view('mypage_finance', [
            'me' => PersonalCases::meInfo($me),
            'cases' => $cases,
            'myAssign' => PersonalCases::myAssign($me),
            'finances' => $finances,
        ]);
    }

    /**
     * 収支（売上・経費明細・メモ）を project_finances へ保存する。
     * 画面から JSON（AJAX）で呼ばれる想定。成功時は {ok:true} を返す。
     * 案件1件＝1行（project_id が同じなら上書き）。
     */
    public function save(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', Rule::exists('projects', 'id')],
            'revenue' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'array'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);

        ProjectFinance::updateOrCreate(
            ['project_id' => $data['project_id']],
            [
                'revenue' => $data['revenue'] ?? null,
                'items' => $this->cleanItems($request->input('items', [])),
                'memo' => $data['memo'] ?? null,
                // 入力者（updated_by）は認証接続後に入れる。今は null のまま。
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * 経費明細を安全な形に整える。
     *   形＝ {費目キー: {qty: 数量, amount: 金額}} の連想配列。
     *   ・数値以外は無視、負数は 0 にクリップ（円・数量はいずれも整数で扱う）。
     *   ・何も入っていない（すべて 0/空）の費目は保存しない。
     */
    private function cleanItems($items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $key => $row) {
            if (! is_string($key) || ! is_array($row)) {
                continue;
            }

            $entry = [];
            foreach (['qty', 'amount'] as $field) {
                if (! array_key_exists($field, $row)) {
                    continue;
                }
                $value = $row[$field];
                if (! is_numeric($value)) {
                    continue; // 数値以外は無視
                }
                $value = (int) $value;
                if ($value < 0) {
                    $value = 0; // 負数はクリップ
                }
                $entry[$field] = $value;
            }

            // 空（数量も金額も 0）の費目は保存しない
            $hasValue = false;
            foreach ($entry as $value) {
                if ($value > 0) {
                    $hasValue = true;
                }
            }
            if ($entry && $hasValue) {
                $clean[$key] = $entry;
            }
        }

        return $clean;
    }
}
