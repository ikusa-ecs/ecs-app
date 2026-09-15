<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\WeekStart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * カレンダーの「週のはじまり」を本人が選ぶ（2026-09-15 baba要望）。
 *
 * ⚠ 変えられるのは**自分のぶんだけ**（人のIDを受け取らない）。画面の色と同じ作り。
 * ⚠ どんな並びがあるか・知らない値の扱いは App\Support\WeekStart が正本。
 */
class WeekStartController extends Controller
{
    public function save(Request $request)
    {
        $data = $request->validate([
            'week_start' => ['nullable', 'string', 'max:10'],
        ]);

        $person = Auth::user();
        if (! $person instanceof Person) {
            return back();
        }

        $value = WeekStart::normalize($data['week_start'] ?? null);
        $person->week_start = $value;
        $person->save();

        return back()->with('status', 'カレンダーを「'.(WeekStart::OPTIONS[$value] ?? '日曜はじまり').'」にしました。');
    }
}
