<?php

namespace App\Support;

use App\Models\SfInboxItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * SFの商談を読んできて、受信箱（sf_inbox_items）に入れる（2026-11-20）。
 *
 * 【決まり】
 *  ・ここがやるのは「受信箱に入れる」まで。案件（projects）は1文字も変えない。
 *    案件になるのは、人が受信箱やマイページで「案件として登録」を押したときだけ（自動登録はしない＝11/20決定）。
 *  ・5〜10分おきに動く（routes/console.php）。前に読めた時刻より後に変わった商談だけを読む。
 *  ・開催日が services.salesforce.from_date（はじめは 2027-02-01＝2月分）以降の商談だけ読む。
 *  ・まだ受信箱に無い商談は、アサインに関係するフェーズ（NEW_STAGES）のときだけ入れる。
 *    失注などは新しく入れない（アサイン表に出ない案件は要らない）。
 *  ・もう受信箱にある商談は、「取り込み待ち」のあいだだけ中身を新しくする。
 *    登録ずみ・見送りは触らない（登録後の変更を知らせるのは、あとで作る追加機能）。
 *
 * ⚠ SFの LastModifiedDate は世界標準時（UTC）。日本時間のまま比べると9時間ずれて取りこぼす。
 */
final class SfPull
{
    /** 前に読めた時刻（UTC）を覚えておく名前。 */
    private const LAST_OK_KEY = 'sf_pull.last_ok';

    /** 取りこぼし防止に、前に読めた時刻より少し前から読み直す（分）。同じ商談を2回読んでも結果は同じ。 */
    private const OVERLAP_MINUTES = 10;

    /**
     * 新しく受信箱に入れるフェーズ。
     * ⚠ SfOpportunityMap の STAGE_TO_YOMI（フェーズ→ヨミ）と同じ並びにしておく。あちらを足したらここも足す。
     */
    public const NEW_STAGES = ['受注', '終了', 'Aヨミ', 'Bヨミ', 'Cヨミ'];

    /**
     * 商談から読む項目。名前の正本は SfOpportunityMap の定数（ここに文字で書かない）。
     * ⚠ 屋内・屋外（F_INOUT）はSFに作らないことになったので読まない
     *   （SFに無い項目を1つでも書くと、検索全体がエラーになる）。
     */
    private static function fields(): array
    {
        return [
            SfOpportunityMap::F_ID, SfOpportunityMap::F_NAME, SfOpportunityMap::F_STAGE, SfOpportunityMap::F_AMOUNT,
            'LastModifiedDate',
            SfOpportunityMap::F_ACCOUNT, SfOpportunityMap::F_OWNER_EMAIL,
            SfOpportunityMap::F_DATE, SfOpportunityMap::F_DAYS, SfOpportunityMap::F_GUEST,
            SfOpportunityMap::F_OFFICE, SfOpportunityMap::F_SCALE, SfOpportunityMap::F_AGENCY,
            SfOpportunityMap::F_ONLINE_TOOL,
            SfOpportunityMap::F_FORMAT, SfOpportunityMap::F_STAFF, SfOpportunityMap::F_VENUE,
            SfOpportunityMap::F_TEAMS, SfOpportunityMap::F_MEET, SfOpportunityMap::F_LEAVE,
            SfOpportunityMap::F_EVENT_START, SfOpportunityMap::F_EVENT_END,
            SfOpportunityMap::F_LODGING, SfOpportunityMap::F_NOTE,
        ];
    }

    /** SFに投げる検索文。$sinceUtc が null なら「変わった時刻」で絞らず全部読む。 */
    public static function soql(?Carbon $sinceUtc): string
    {
        // 商品の明細は、商品の18桁の番号（Product2Id）も一緒に取る＝ECSの対応表はこの番号で引く。
        $lines = 'SELECT Quantity, '.SfOpportunityMap::F_LINE_PRODUCT_ID.', '
            .SfOpportunityMap::F_LINE_PRODUCT_ID_ALT.', '.SfOpportunityMap::F_LINE_PRODUCT_NAME
            .' FROM OpportunityLineItems';

        $from = (string) config('services.salesforce.from_date', '2027-02-01');
        // 日付の形でなければ使わない（設定の書き間違いで検索文が壊れないように）
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $from)) {
            $from = '2027-02-01';
        }

        $where = SfOpportunityMap::F_DATE.' >= '.$from;
        if ($sinceUtc !== null) {
            $where .= ' AND LastModifiedDate >= '.$sinceUtc->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return 'SELECT '.implode(', ', self::fields()).', ('.$lines.') FROM Opportunity WHERE '.$where
            .' ORDER BY LastModifiedDate';
    }

    /**
     * 読んで受信箱に入れる。
     *
     * @param  bool  $all  true＝前回の時刻を無視して全部読み直す（夜に1回・手動の確認用）
     * @return array{status: string, read: int, created: int, updated: int, message: string}
     *   status＝off（鍵が未設定＝何もしていない）／ok／error
     */
    public static function run(bool $all = false): array
    {
        $result = ['status' => 'off', 'read' => 0, 'created' => 0, 'updated' => 0, 'message' => 'SFの鍵がまだ設定されていません。'];
        if (! SfClient::configured()) {
            return $result;
        }

        // 読み始めた時刻を覚える（読んでいる最中に変わった商談を、次の回で拾えるように「終わった時刻」ではなく「始めた時刻」）
        $startedUtc = now()->utc();
        $since = null;
        if (! $all) {
            $last = Cache::get(self::LAST_OK_KEY);
            if (is_string($last) && $last !== '') {
                try {
                    $since = Carbon::parse($last)->utc()->subMinutes(self::OVERLAP_MINUTES);
                } catch (\Throwable) {
                    $since = null;   // 覚えていた時刻が壊れていたら、全部読み直す（安全側）
                }
            }
        }

        try {
            $records = SfClient::query(self::soql($since));
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'error', 'read' => 0, 'created' => 0, 'updated' => 0, 'message' => $e->getMessage()];
        }

        $result = ['status' => 'ok', 'read' => count($records), 'created' => 0, 'updated' => 0, 'message' => ''];
        foreach ($records as $record) {
            // 1件おかしくても、ほかの商談は入れる
            try {
                $r = self::store($record);
                if ($r !== null) {
                    $result[$r]++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Cache::forever(self::LAST_OK_KEY, $startedUtc->toIso8601String());
        $result['message'] = "SFから{$result['read']}件読みました（新しく受信箱へ {$result['created']}件・中身を更新 {$result['updated']}件）。";

        return $result;
    }

    /**
     * 商談1件を受信箱へ。
     *
     * @return 'created'|'updated'|null  null＝何もしなかった
     */
    public static function store(array $record): ?string
    {
        $id = $record[SfOpportunityMap::F_ID] ?? null;
        if (! is_string($id) || strlen($id) !== 18) {
            return null;   // 18桁の商談Idが無いものは入れない（同じ商談かどうか見分けられない）
        }
        $payload = self::clean($record);
        $email = SfOpportunityMap::get($payload, SfOpportunityMap::F_OWNER_EMAIL);
        $email = is_string($email) && $email !== '' ? $email : null;

        $item = SfInboxItem::where('sf_opportunity_id', $id)->first();
        if ($item === null) {
            $stage = $payload[SfOpportunityMap::F_STAGE] ?? null;
            if (! in_array($stage, self::NEW_STAGES, true)) {
                return null;
            }
            SfInboxItem::create([
                'sf_opportunity_id' => $id,
                'payload' => $payload,
                'status' => SfInboxItem::WAITING,
                'owner_email' => $email,
                'received_at' => now(),
                'is_sample' => false,
            ]);

            return 'created';
        }

        // 見本（is_sample）と、人がもう決めたもの（登録ずみ・見送り）は触らない
        if ($item->is_sample || $item->status !== SfInboxItem::WAITING) {
            return null;
        }
        if ($item->payload == $payload && $item->owner_email === $email) {
            return null;
        }
        $item->update(['payload' => $payload, 'owner_email' => $email]);

        return 'updated';
    }

    /** SFが付けてくる管理用の印（attributes）を落とす。ECSでは使わず、中身の見比べのじゃまになる。 */
    private static function clean(array $value): array
    {
        unset($value['attributes']);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::clean($v);
            }
        }

        return $value;
    }
}
