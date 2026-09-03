<?php

namespace App\Http\Middleware;

use App\Support\HireDate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 入社年月日の「年・月・日の3つのプルダウン」を、これまでどおりの `hire_date` に組み立て直す（2026-09-03）。
 *
 * ⚠ ここが無いと、各コントローラ（初期設定・マイページ・名簿・スタッフ画面）を全部直すことになる。
 *   組み立て方が4か所に散ると、片方だけ直して食い違うので、**入口をここ1つ**にしている。
 *   組み立ての決まりそのものは [[\App\Support\HireDate]] が持つ。
 *
 * ⚠ `hire_y` が送られてきたときだけ働く＝古い `hire_date` 1本の送り方（CSV取込など）はそのまま動く。
 */
class NormalizeHireDate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('hire_y') || $request->has('hire_m') || $request->has('hire_d')) {
            $request->merge([
                'hire_date' => HireDate::compose(
                    $request->input('hire_y'),
                    $request->input('hire_m'),
                    $request->input('hire_d'),
                ),
            ]);
        }

        return $next($request);
    }
}
