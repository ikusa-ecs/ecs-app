<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\Themes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 画面の色（テーマ）を本人が選ぶ（2026-09-09 baba要望「カラーリングを個人によって変更できる？」）。
 *
 * ⚠ 変えられるのは**自分のぶんだけ**。他人の色は変えられない（人のIDを受け取らない）。
 * ⚠ どんな色があるか・知らない値の扱いは App\Support\Themes が正本。ここで判定を書き足さない。
 * ⚠ 色そのものは public/ecs/style.css。PHPには色を書かない。
 */
class ThemeController extends Controller
{
    public function save(Request $request)
    {
        $data = $request->validate([
            'theme' => ['nullable', 'string', 'max:20'],
        ]);

        $person = Auth::user();
        if (! $person instanceof Person) {
            return back();
        }

        // ⚠ 知らない値は既定に寄せる（URLを書き換えられても画面が崩れないように）。
        $theme = Themes::normalize($data['theme'] ?? '');
        $person->theme = $theme;
        $person->save();

        return back()->with('status', '画面の色を「'.(Themes::OPTIONS[$theme] ?? 'いまの色').'」にしました。');
    }
}
