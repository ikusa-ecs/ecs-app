<?php

namespace App\Http\Controllers;

use App\Support\DangerCalendar;
use App\Support\DangerDayRule;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 危険日の一覧を機械（GAS）に渡す口（GET /danger-days・2026-09-16 baba要望）。
 *
 * これを読んだGASが、イベプラのGoogleカレンダーに「危険日」の予定を入れる。
 * 手順書＝`稼働管理\ECS\危険日をカレンダーに入れるGAS.txt`。
 *
 * ⚠ **ECSはカレンダーに触らない。** 「どの日が危険日か」「何と書くか」を返すだけ。
 *   理由＝Googleの鍵をECSに持たせないため（GASは onuma@ikusa.co.jp 自身として動くので鍵が要らない）。
 *
 * ⚠ **ログインの外側にある口**なので、合言葉（アサイン表の受け取りと同じもの）で門を作る。
 *   合言葉を決めていなければ、何が来ても返さない。
 * ⚠ **GET（見るだけ）にしてある。** データを1つも書き換えないので、CSRFの除外を増やさずに済む
 *   （CSRFの除外は sheet-sync の1つだけ、という決まりを守る）。
 */
class DangerDayFeedController extends Controller
{
    public function index(Request $request)
    {
        $expected = (string) config('ecs.sheet_sync_token');
        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'message' => '危険日の受け渡しは、いま止まっています（合言葉が設定されていません）。',
            ], 404);
        }

        $given = (string) ($request->query('token') ?? $request->header('X-ECS-Token', ''));
        if (! hash_equals($expected, $given)) {
            return response()->json(['ok' => false, 'message' => '合言葉が違います。'], 403);
        }

        // 拠点。知らない拠点名は受け取らない（勘で東京にしない＝アサイン表の受け取りと同じ考え方）。
        $office = trim((string) $request->query('office', OfficeScope::DEFAULT_OFFICE));
        if ($office !== '' && ! in_array($office, OfficeScope::options(), true)) {
            return response()->json(['ok' => false, 'message' => '知らない拠点名です：'.$office], 422);
        }

        // 何か月先まで見るか。既定＝6か月（2026-09-16 baba）。1〜12の範囲に収める。
        $months = (int) $request->query('months', DangerCalendar::MONTHS_AHEAD);
        $months = max(1, min(12, $months));

        $from = Carbon::today();
        $to = $from->copy()->addMonthsNoOverflow($months);

        $days = DangerDayRule::between($from, $to, $office ?: null);

        return response()->json([
            'ok' => true,
            'office' => $office,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            // 予定の中身。⚠ 文面はECS側（共通設定）が正本＝GASの中に文章を書かない
            //   （書くと、直すたびにGASを開くことになる）。
            'title' => DangerCalendar::title(),
            'start' => DangerCalendar::START_TIME,
            'end' => DangerCalendar::END_TIME,
            // ECSが入れた予定の目印。GASはこれが付いた予定だけを消してよい。
            'mark' => DangerCalendar::MARK,
            'days' => array_map(fn (array $d) => [
                'date' => $d['date'],
                'kind' => $d['kind'],          // 自動／手動／自動と手動
                'reasons' => $d['reasons'],
                'projects' => $d['projects'],
                'body' => DangerCalendar::body($d['reasons']),
            ], $days),
        ]);
    }
}
