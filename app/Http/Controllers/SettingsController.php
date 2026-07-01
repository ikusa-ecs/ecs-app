<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Support\AssignmentRole;

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
        ]);
    }
}
