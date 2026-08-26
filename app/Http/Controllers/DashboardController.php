<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\DangerDays;
use App\Support\Headcount;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
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
 * ※ KPI・危険日カレンダー・件数集計とも、この $cases（DBのprojects由来）で描く＝本物のデータ。
 *   件数集計は実施形態(format)の生テキストを画面側で「拠点(種別)」に解釈して月別集計する。
 *   唯一、危険日判定で使う「稼働スタッフ40名」だけは暫定の目安（画面側 cases.js の定数）。
 */
class DashboardController extends Controller
{
    public function index(Request $request)
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
                    'need'     => Headcount::label($p->required_count_min, $p->required_count),
                    'off'      => $off,
                    // 危険日カレンダーのマスにカーソルを当てたとき表示する案件の中身。
                    // 取り出し方は ProjectController の案件一覧と揃える（client / sales_owners 先頭 / 集合・解散時間）。
                    'client'   => $p->client ?? '',
                    'sales'    => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
                    'meet'     => $p->start_time ?? '—',
                    'leave'    => $p->end_time ?? '—',
                    // 実施形態（リアル／リアルロング／オンライン 等）。「今月の件数集計」の“種別”に使う。
                    'format'   => $p->format ?? '',
                    // 登録拠点。「今月の件数集計」の“拠点”に使う（2026-08-25 修正）。
                    // ⚠ 以前は実施形態の「イベント東(リアル)」というカッコ付きの文字から拠点を読んでいた。
                    //   2026-07-31 の全拠点対応で拠点は projects.office に移ったのに、集計だけ古いままで、
                    //   カッコが無い今のデータでは拠点も種別も読めず**全部「その他」**に落ちていた。
                    'office'   => $p->office ?? '',
                    // cases.js では下書き・アーカイブは別フラグ。DB では status にまとめているので戻す。
                    'draft'    => $p->status === '下書き',
                    'archived' => $p->status === '完了',
                ];
            })
            ->values();

        return view('dashboard', [
            'cases' => $cases,
            // 件数集計の「拠点」の並び。⚠ 画面に拠点名を直書きしない（正本＝拠点マスタ）。
            'offices' => OfficeScope::options(),
            // 危険日（手動指定）＝設定画面で足した日。カレンダーが自動判定に加えて赤くする。
            // ⚠ 拠点ごとの危険日に対応（2026-08-26）。出すのは「全拠点共通 ＋ 自分の拠点」。
            //   管理者が「全拠点」で見ているとき（filter が null）は、どこかの拠点の危険日も出す。
            'manualDanger' => DangerDays::dates(OfficeScope::filter($request)),
        ]);
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
