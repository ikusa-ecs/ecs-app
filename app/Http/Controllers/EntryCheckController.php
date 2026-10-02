<?php

namespace App\Http\Controllers;

use App\Support\EntryCheck;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * エントリーの点検（見るだけ）。判定の正本＝App\Support\EntryCheck。
 * 2026-10-02 baba：取込でIDがずれた件で、エントリーが別の案件を指していないかを絞り込む。
 */
class EntryCheckController extends Controller
{
    public function index(Request $request)
    {
        $from = (string) $request->query('from', '2026-09-01');
        try {
            $since = Carbon::parse($from)->startOfDay();
        } catch (\Throwable $e) {
            $since = Carbon::parse('2026-09-01');
        }

        $rows = EntryCheck::rows($since);
        // 全体LINEに貼る一覧は、絞り込みの前のものから作る（ガラッとの案件だけ）。
        $announce = EntryCheck::announceText($rows['changed']);
        // 「案件名がガラッと変わったものだけ」（2026-10-02 baba）。チェックを付けたときだけ絞る。
        $bigOnly = $request->boolean('big');
        if ($bigOnly) {
            $rows['changed'] = array_values(array_filter($rows['changed'], fn ($r) => $r['big_name']));
        }

        return view('entry_check', [
            'from' => $since->format('Y-m-d'),
            'bigOnly' => $bigOnly,
            'announce' => $announce,
            // 重複の疑いは「この日以降に開催する案件」で探す（変更の日付と同じ入力を使う）。
            'dups' => EntryCheck::sameDayDuplicates($since),
        ] + $rows);
    }
}
