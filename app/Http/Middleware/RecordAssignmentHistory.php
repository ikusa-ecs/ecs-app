<?php

namespace App\Http\Middleware;

use App\Support\AssignmentHistory;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * アサインの変更を、保存が1回終わったところでまとめて編集履歴に書く（2026-11-25 小沼さん）。
 *
 * 【なぜここで書くか】保存の途中で1行ずつ書くと、「全員消して入れ直す」作りの保存で
 *   変えていない人まで履歴に並ぶ。保存が終わってから「前と後の差」だけを書く。
 *   ためる側・書く中身の正本＝App\Support\AssignmentHistory。
 *
 * ⚠ 保存が失敗した（エラーの返事）ときは書かない＝DBは元に戻っているのに履歴だけ残る、を防ぐ。
 * ⚠ 過去のアサイン表の取込（/past-import）は書かない＝昔の記録を何百行も入れる作業で、
 *    「だれが入れた・外した」を追う対象ではないため（履歴が埋もれる）。
 */
class RecordAssignmentHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        // 見るだけの操作（GET）では見張らない＝画面を開くたびに余計な仕事をさせない。
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        AssignmentHistory::begin();   // 見張りを始める（前の処理の残りは持ち越さない）

        $response = $next($request);

        if ($response->getStatusCode() >= 400 || $request->is('past-import*')) {
            AssignmentHistory::discard();
        } else {
            try {
                AssignmentHistory::flush();
            } catch (\Throwable $e) {
                // 履歴は補助の機能。ここで失敗しても、保存そのものの返事は止めない。
                AssignmentHistory::discard();
                report($e);
            }
        }

        return $response;
    }
}
