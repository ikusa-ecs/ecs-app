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

        return view('entry_check', [
            'from' => $since->format('Y-m-d'),
        ] + EntryCheck::rows($since));
    }
}
