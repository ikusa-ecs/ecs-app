<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Support\AssignMtg;
use App\Support\AssignmentRole;
use Illuminate\Http\Request;

/**
 * 設定（S / /settings）。
 *
 * これまで画面はマスタ件数（拠点◯件／コンテンツ◯件／ポジション◯件）を
 * HTML にベタ書きしていた＝DB の実数とズレる「嘘表示」だった。
 * ここでは実データから件数を数えて渡す（表示だけ・保存はまだモック）。
 *
 * ※「拠点」の元データは people.office（事務所）を使う。専用の拠点マスタ表は未整備。
 *   もし「拠点＝実施場所（イベント東 等）」の意味に変えるなら、集計元を差し替える。
 */
class SettingsController extends Controller
{
    public function index()
    {
        // 拠点＝事務所（people.office）の種類数。
        $offices = Person::whereNotNull('office')
            ->where('office', '!=', '')
            ->distinct()
            ->pluck('office');

        // ポジション（役割）＝正本 AssignmentRole の全コード。
        $positionLabels = array_values(AssignmentRole::LABELS);

        return view('settings', [
            'masterCounts' => [
                'offices' => [
                    'count' => $offices->count(),
                    'examples' => $offices->implode('／'),
                ],
                'contents' => [
                    'count' => Content::count(),
                ],
                'positions' => [
                    'count' => count($positionLabels),
                    'examples' => implode('／', $positionLabels),
                ],
            ],
            // アサインMTG日の予定表（DB保存の一覧・昇順）。案件登録の「追加案件」自動判定に使う。
            'assignMtgDates' => AssignMtg::dates(),
            // 今日までで一番新しいMTG日＝現在の基準日（無ければ null）。表示用。
            'assignMtgCurrent' => AssignMtg::current(),
        ]);
    }

    /**
     * アサインMTG日の予定表を保存する（POST /settings/mtg-dates）。
     * 全員に効く共通設定なので settings テーブル（key='assign_mtg_dates'）にまとめて保存する。
     * 複数の日付を登録でき、案件登録フォームは「今日までで一番新しいMTG日」を基準に
     * 開催日がそれより後の登録を自動で「追加案件」にする。
     */
    public function saveMtgDates(Request $request)
    {
        $data = $request->validate([
            'dates'   => ['present', 'array'],
            'dates.*' => ['date'],
        ]);

        $list = AssignMtg::save($data['dates']);

        return response()->json([
            'ok'      => true,
            'dates'   => $list,
            'current' => AssignMtg::current(),
        ]);
    }
}
