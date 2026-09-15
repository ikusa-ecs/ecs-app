<?php

namespace App\Http\Controllers;

use App\Models\SheetSync;
use App\Support\MonthlySheetReader;
use App\Support\OfficeScope;
use App\Support\SheetSyncNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * アサイン表の自動受け取り（2026-09-10 baba要望）。
 *
 * 【なぜ要るか】
 * ECSとアサイン表の二重管理をしていて、**どこまでECSに反映したか分からない**のが困りごと。
 * 毎朝スプレッドシート側の仕掛け（GAS）がアサイン表の中身を送ってきて、
 * ECSは受信箱に置く。人は「変わったところ」だけを見て、承認したものを反映する。
 *
 * 【入口が2つある】
 *  ・POST /sheet-sync … 機械が叩く（ログインの外側・合言葉で門を作る）
 *  ・GET  /sheet-inbox … 人が見る（管理者以上）
 *
 * ⚠ 届いた時点で**ECSのデータは1文字も変わらない**。ここは受信箱に置くだけ。
 *   反映は人が「アサイン表の取込」の画面で押したときだけ動く
 *   ＝勝手に反映すると、ECS側で人が直した内容を機械が上書きしてしまう。
 *
 * ⚠ この入口はログインの門の外にある。9/7に見つかった権限の穴（門の付け忘れ・
 *   public に置いたファイル）と同じ失敗をしないよう、次の3つで守る。
 *   ①合言葉（config('ecs.sheet_sync_token')）が一致しないと何もしない
 *   ②合言葉を決めていなければ入口そのものを閉じる（書き忘れが事故にならない）
 *   ③できるのは「受信箱に置くこと」だけ＝案件・アサインは書き換わらない
 */
class SheetSyncController extends Controller
{
    /**
     * ECSの案件IDをアサイン表の何行目に書き戻すか（1はじまり・2026-09-15 baba指定）。
     *
     * ⚠ **行を増やさない。** アサイン表は行の位置でレイアウトが決まっているので、
     *   途中に挿し込むと全部ずれる。100行目より下は今も空いているので、そこを使う。
     * ⚠ 変えるときはここだけ直す（GASの手順書にもこの数字を書いてある）。
     */
    public const ID_ROW = 100;

    /**
     * 毎朝スプレッドシートから届く入口（ログインの外側）。
     *
     * 送ってもらう形（JSON）：
     *   { token: '合言葉', book: 'ファイル名', tab: '202610', office: '東京',
     *     rows: [[1行目の列...], [2行目の列...], ...] }
     */
    public function receive(Request $request)
    {
        $expected = (string) config('ecs.sheet_sync_token');
        if ($expected === '') {
            // 合言葉を決めていない＝この機能は使わない。何が届いても受け取らない。
            return response()->json([
                'ok' => false,
                'message' => 'アサイン表の自動受け取りは、いま止まっています（合言葉が設定されていません）。',
            ], 404);
        }

        // ⚠ 文字列の比較は hash_equals を使う（1文字ずつ当てられるのを防ぐ）。
        $given = (string) ($request->input('token') ?? $request->header('X-ECS-Token', ''));
        if (! hash_equals($expected, $given)) {
            return response()->json(['ok' => false, 'message' => '合言葉が違います。'], 403);
        }

        // 毎朝の「まとめ報告」（2026-09-15 baba要望）。GASが全部送り終わったあとに1回だけ叩く。
        // ⚠ **わざとURLを増やしていない。** CSRFの除外は bootstrap/app.php に1つ（sheet-sync）だけ、
        //   という決まりを守るため。入口が増えるほど、守り忘れる場所が増える。
        if ($request->input('kind') === 'report') {
            return $this->handleReport($request);
        }

        $data = $request->validate([
            'book' => ['nullable', 'string', 'max:200'],
            'tab' => ['required', 'string', 'max:40'],
            'office' => ['nullable', 'string', 'max:20'],
            // シートの中身。1行＝列の配列。
            'rows' => ['required', 'array', 'min:2', 'max:3000'],
            'rows.*' => ['array'],
        ]);

        // 何年何月ぶんか。タブ名（202610 など）から読む。
        // ⚠ 読み方の正本は MonthlySheetReader（CSVのファイル名から読むのと同じもの）。
        $period = MonthlySheetReader::periodFromFilename((string) $data['tab']);
        if ($period === null) {
            return response()->json([
                'ok' => false,
                'message' => 'タブ名「'.$data['tab'].'」から何年何月ぶんかを読み取れませんでした。'
                    .'日程に年が書かれていないため、年が分からないと取り込めません。'
                    .'タブ名を 202610 のような形にしてください。',
            ], 422);
        }

        // 拠点。知らない拠点名が届いたら受け取らない（勘で東京にしない）。
        $office = trim((string) ($data['office'] ?? '')) ?: '東京';
        if (! in_array($office, OfficeScope::options(), true)) {
            return response()->json([
                'ok' => false,
                'message' => '知らない拠点名です：'.$office,
            ], 422);
        }

        // 行の中身は文字にそろえる（数字や日付で届いても、CSVと同じ「文字の表」として扱う）。
        $rows = array_values(array_map(
            fn ($row) => array_values(array_map(
                fn ($cell) => is_scalar($cell) ? (string) $cell : '',
                is_array($row) ? $row : []
            )),
            $data['rows']
        ));

        $ym = sprintf('%04d-%02d', $period['year'], $period['month']);
        $fingerprint = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE));
        $now = Carbon::now();

        $sync = SheetSync::firstOrNew(['office' => $office, 'period' => $ym]);
        // 前回と同じ中身なら「変わった日時」は動かさない（＝人に見せる用が無い）。
        $changed = $sync->fingerprint !== $fingerprint;

        $sync->fill([
            'source' => 'gas',
            'book' => $data['book'] ?? null,
            'tab' => $data['tab'],
            'rows' => $rows,
            'case_count' => $this->countCases($rows),
            'fingerprint' => $fingerprint,
            'received_at' => $now,
        ]);
        if ($changed) {
            $sync->changed_at = $now;
        }
        $sync->save();

        return response()->json([
            'ok' => true,
            'period' => $ym,
            'office' => $office,
            'cases' => $sync->case_count,
            // 前回と中身が違ったか（GAS側のログに出す用）。
            'changed' => $changed,
            // ECSの案件IDの対応表（2026-09-15 baba要望）。{ "列番号(0はじまり)": "P-2026-0012" }。
            // GASはこれを受け取って、アサイン表の**100行目**の同じ列に書き込む。
            // ⚠ まだ一度も取り込んでいない月は空。取り込んだあと、次の朝から入る。
            // ⚠ 行を増やさない（100行目より下は空いている、という baba の指定）。
            'projectIds' => (object) ($sync->project_ids ?? []),
            'idRow' => self::ID_ROW,
            'message' => $changed
                ? '受け取りました（前回と中身が変わっています）。'
                : '受け取りました（前回と同じ中身です）。',
        ]);
    }

    /**
     * 毎朝の「まとめ報告」をチャットワークへ1通流す（2026-09-15 baba要望）。
     *
     * 【なぜ月ごとではないか】
     * 届くのは今月〜2027年6月ぶん＝10か月ぶん。月ごとに知らせると毎朝10通になって、
     * 誰も読まなくなる。GAS側で数えてから、**1日1通**にまとめる。
     *
     * ⚠ 知らせに失敗しても 200 を返す（GAS側を止めない）。
     *   受け取りそのものは済んでいるので、チャットに出ないだけの話にする。
     */
    private function handleReport(Request $request)
    {
        $data = $request->validate([
            'office' => ['nullable', 'string', 'max:20'],
            'sent' => ['nullable', 'integer', 'min:0', 'max:999'],
            'changed' => ['nullable', 'integer', 'min:0', 'max:999'],
            'changedPeriods' => ['nullable', 'array', 'max:50'],
            'changedPeriods.*' => ['string', 'max:20'],
            'wrote' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'errors' => ['nullable', 'array', 'max:50'],
            'errors.*' => ['string', 'max:300'],
        ]);

        $posted = SheetSyncNotice::morningReport([
            'office' => trim((string) ($data['office'] ?? '')) ?: '東京',
            'sent' => (int) ($data['sent'] ?? 0),
            'changed' => (int) ($data['changed'] ?? 0),
            'changedPeriods' => array_values($data['changedPeriods'] ?? []),
            'wrote' => (int) ($data['wrote'] ?? 0),
            'errors' => array_values($data['errors'] ?? []),
        ]);

        return response()->json([
            'ok' => true,
            'posted' => $posted,
            'message' => $posted
                ? 'チャットワークに知らせました。'
                : 'チャットワークの設定が無いので、知らせは送っていません（受け取りは済んでいます）。',
        ]);
    }

    /** 受信箱の一覧（人が見る画面）。 */
    public function inbox(Request $request)
    {
        // 拠点のしぼり込みは他の画面と同じ決まり（はじめは自分の拠点）。正本＝OfficeScope。
        // ⚠ ここは filterSingle＝必ずどこか1拠点（「全拠点」は無し）。
        //   受け取ったシートは拠点ごとに別物なので、混ぜて出すと取り違えのもとになる。
        $office = OfficeScope::filterSingle($request);

        $rows = SheetSync::where('office', $office)->orderBy('period')->get();

        return view('sheet_inbox', [
            'rows' => $rows,
            'offices' => OfficeScope::options(),
            'office' => $office,
            // 合言葉を決めていなければ、まだ届かない（画面で理由が分かるように）。
            'ready' => (string) config('ecs.sheet_sync_token') !== '',
        ]);
    }

    /**
     * 読めた案件の数。
     * ⚠ 数え方は MonthlySheetReader の1か所（画面用に別の数え方を作らない）。
     */
    private function countCases(array $rows): int
    {
        if (! MonthlySheetReader::looksLikeMonthlySheet($rows)) {
            return 0;
        }

        return count(MonthlySheetReader::read($rows)['cases']);
    }
}
