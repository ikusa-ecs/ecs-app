<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * ダッシュボード（/dashboard）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）をブラウザで読み、
 * KPI（今月/今週/大型/来月の案件数）と危険日カレンダーを描いていた。
 * ここでは DB の projects テーブルを読み、cases.js と「同じ形」のデータ
 * （ECS_CASES）に詰め替えて Blade に渡す。
 * 危険日の判定ロジック（ECS_caseDate / ECS_dangerCheck）は cases.js の
 * 共通関数をそのまま使うので、見た目・計算式は変えずにデータの出どころ
 * だけ DB に切り替えられる。
 *
 * ※「今月の件数集計」表（拠点別・種別内訳）は、DB 側に拠点・実施形態の
 *   細分類がまだ揃っていないため、画面側のベタ書き（仮）のまま据え置く。
 *   ここで渡すのは KPI とカレンダーが使う案件リストだけ。
 */
class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // cases.js と同じ項目名に詰め替える（既存の表示JSをそのまま動かすため）。
        // KPI とカレンダーが使う項目だけに絞る：off / scale / fmt / need / name / 下書き・完了。
        $cases = Project::orderBy('start_date')
            ->get()
            ->map(function (Project $p) use ($today) {
                // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。cases.js の off と同じ意味。
                $off = $p->start_date
                    ? intdiv(
                        $p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp,
                        86400
                    )
                    : 0;

                return [
                    'id'       => $p->id,
                    'name'     => $p->project_name,
                    'scale'    => $p->scale ?? '',
                    // fmt ＝ 実施形態の種別コード（real / long / online）。
                    // 危険日判定が c.fmt を見るので、format テキストから cases.js と同じ規則で求める。
                    'fmt'      => $this->fmtCode($p->format),
                    'need'     => $p->required_count ?? '',
                    'off'      => $off,
                    // 危険日カレンダーのマスにカーソルを当てたとき表示する案件の中身。
                    // 取り出し方は ProjectController の案件一覧と揃える（client / sales_owners 先頭 / 集合・解散時間）。
                    'client'   => $p->client ?? '',
                    'sales'    => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
                    'meet'     => $p->start_time ?? '—',
                    'leave'    => $p->end_time ?? '—',
                    // 実施形態の生テキスト（例「イベント東(リアル)」）。
                    // 「今月の件数集計」が拠点(種別)を読み解いて月別に集計するのに使う。
                    'format'   => $p->format ?? '',
                    // cases.js では下書き・アーカイブは別フラグ。DB では status にまとめているので戻す。
                    'draft'    => $p->status === '下書き',
                    'archived' => $p->status === '完了',
                ];
            })
            ->values();

        return view('dashboard', ['cases' => $cases]);
    }

    /**
     * 実施形態の文字列 → 種別コード（real / long / online）。
     * cases.js の window.ECS_fmtCode と同じ規則（判定をDB側でも揃える）。
     */
    private function fmtCode(?string $formatText): string
    {
        $t = (string) $formatText;
        if (str_contains($t, 'オンライン') || str_contains($t, 'ヘルプのみ')) {
            return 'online';
        }
        if (str_contains($t, 'リアルロング')) {
            return 'long';
        }

        return 'real'; // リアル・ARENA・体験会・巻き取り 等はリアル系として扱う
    }
}
