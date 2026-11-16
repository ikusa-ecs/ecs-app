<?php

namespace App\Support;

use App\Models\Content;
use App\Models\Person;
use App\Models\SfProduct;

/**
 * Salesforce（SF）の商談（Opportunity）の中身 → ECSの案件の項目 の読み替えの正本（2026-11-16）。
 *
 * 【ここだけ直せばよい作り】
 *   SFの項目名（API参照名）はすべてこのクラスの定数 F_*** に集めてある。
 *   SF側で項目が作られたら・名前が変わったら、ここの定数と読み替えだけ直す（画面やテーブルは直さない）。
 *   受信箱（sf_inbox_items.payload）には SF から来たままのJSONが入っている。
 *
 * 【受け取る中身の形】SF の REST API（SOQL の結果1件）と同じ形を想定：
 *   {
 *     "Id": "006...", "Name": "…", "StageName": "Aヨミ", "Amount": 3000000,
 *     "Account": {"Name": "…"}, "Owner": {"Email": "…"},
 *     "kaisaibi__c": "2027-01-20", "kaisainissu__c": 1, "ninzu__c": 50,
 *     "tantokyoten__c": "東京オフィス", "uriagegata__c": "中型", "dairiten__r": {"Name": "…"},
 *     "Videochat__c": "zoom",
 *     "Event_Format__c": "リアル", "Staff_Required__c": 6, "Venue_Address__c": "…", …（未確定・下記）
 *     "OpportunityLineItems": {"totalSize": 1, "records": [
 *        {"Quantity": 1, "Product2Id": "01t…", "PricebookEntry": {"Product2": {"Id": "01t…", "Name": "…"}}}
 *     ]}
 *   }
 *
 * ⚠ 案件を作る中身（toProjectAttributes）と、受信箱の下見（preview）は必ず同じ読み替えを通す
 *   （下見と登録で中身が食い違わないように）。
 */
final class SfOpportunityMap
{
    // ── 確認ずみの項目（調査結果 A・B・E で実物を確認）──
    public const F_ID = 'Id';                          // 商談Id（18桁）
    public const F_NAME = 'Name';                      // 商談名
    public const F_STAGE = 'StageName';                // フェーズ（Aヨミ・受注 など）
    public const F_AMOUNT = 'Amount';                  // 金額（税込）※ECSには入れない（参考表示だけ）
    public const F_ACCOUNT = 'Account.Name';           // お客様名（null のことがある）
    public const F_OWNER_EMAIL = 'Owner.Email';        // 担当営業のメール
    public const F_DATE = 'kaisaibi__c';               // 開催日（YYYY-MM-DD・複数日は初日・空のことがある）
    public const F_DAYS = 'kaisainissu__c';            // 開催日数（数値）
    public const F_GUEST = 'ninzu__c';                 // 参加人数（数値・小数もありうる・複数日は合計）
    public const F_OFFICE = 'tantokyoten__c';          // 担当拠点（「東京オフィス」など）
    public const F_SCALE = 'uriagegata__c';            // 売上型＝案件規模（小型/中型/大型＝ECSと同じ文字）
    public const F_AGENCY = 'dairiten__r.Name';        // 代理店名（dairiten__c は参照なので名前は __r.Name で読む）
    public const F_ONLINE_TOOL = 'Videochat__c';       // オンラインツール（既存項目を流用・C-0a）

    // ── ⚠ 未確定（SFにまだ作られていない項目・調査結果 C-1 の予定名）──
    //    作られたら名前が予定どおりか確かめて、違えばここだけ直す。
    public const F_FORMAT = 'Event_Format__c';         // 未確定：実施形態（リアル/リアルロング/オンライン/ARENA場所貸し/体験会）
    public const F_STAFF = 'Staff_Required__c';        // 未確定：運営人数
    public const F_VENUE = 'Venue_Address__c';         // 未確定：会場（住所）
    public const F_TEAMS = 'Team_Count__c';            // 未確定：チーム数
    public const F_MEET = 'Meet_Time__c';              // 未確定：集合時間（テキスト）
    public const F_LEAVE = 'Leave_Time__c';            // 未確定：解散時間（テキスト）
    public const F_EVENT_START = 'Event_Start_Time__c'; // 未確定：イベント開始（テキスト）
    public const F_EVENT_END = 'Event_End_Time__c';    // 未確定：イベント終了（テキスト）
    public const F_LODGING = 'Lodging__c';             // 未確定：宿泊（無/前泊有/一部前泊有/後泊あり/前後泊あり＝ECSと同じ文字）
    public const F_INOUT = 'Indoor_Outdoor__c';        // 未確定：屋内・屋外
    public const F_NOTE = 'ECS_Note__c';               // 未確定：備考（ECSへ渡す）

    // ── 商品（明細）──
    public const F_LINES = 'OpportunityLineItems.records';
    // ⚠ 未確定：商品Idの読み方。調査で試したSOQL（E-1）は商品名しか取っていない。
    //   本番のSOQLには Product2Id（または PricebookEntry.Product2.Id）を足してもらう前提。
    public const F_LINE_PRODUCT_ID = 'Product2Id';
    public const F_LINE_PRODUCT_ID_ALT = 'PricebookEntry.Product2.Id';
    public const F_LINE_PRODUCT_NAME = 'PricebookEntry.Product2.Name';

    /**
     * ⚠ 未確定：フェーズ → ECSの確度（ヨミ）。ECSの選択肢は 確定/Aヨミ/Bヨミ/Cヨミ。
     *   SFのフェーズの全種類はまだ確かめていない。表に無いフェーズは空のまま（推測しない）。
     */
    private const STAGE_TO_YOMI = [
        '受注' => '確定',
        '終了' => '確定',
        'Aヨミ' => 'Aヨミ',
        'Bヨミ' => 'Bヨミ',
        'Cヨミ' => 'Cヨミ',
    ];

    /** オンラインツール：SFの値（小文字にして引く）→ ECSの値。null＝ツールなし。（C-0a） */
    private const ONLINE_TOOLS = [
        'zoom' => 'zoom',
        'teams' => 'Teams',
        'webex' => 'webex',
        'meet' => 'meet',
        'rebako' => 'rebako',
        '利用無し' => null,
    ];

    private const LODGINGS = ['無', '前泊有', '一部前泊有', '後泊あり', '前後泊あり'];

    /** 「Account.Name」のような書き方で、入れ子の中身を取り出す。無ければ null。 */
    public static function get(array $payload, string $path): mixed
    {
        $cur = $payload;
        foreach (explode('.', $path) as $key) {
            if (! is_array($cur) || ! array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }

        return $cur;
    }

    private static function text(array $payload, string $path): ?string
    {
        $v = self::get($payload, $path);
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', (string) $v) ?? '';

        return $s !== '' ? $s : null;
    }

    private static function int(array $payload, string $path): ?int
    {
        $v = self::get($payload, $path);
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return null;
        }

        return (int) round((float) $v);   // 数値型は小数が来うる＝整数にそろえる
    }

    /**
     * 商品の明細 → 対応表で引いた結果。
     *
     * @return list<array{sf_id: ?string, name: string, handling: string, content_id: ?string, content_name: ?string}>
     *   handling＝content（つながった）／ignore（無視）／pending（未対応）／unknown（対応表に無い）
     */
    public static function products(array $payload): array
    {
        $lines = self::get($payload, self::F_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $ids = [];
        foreach ($lines as $line) {
            $id = is_array($line) ? (self::text($line, self::F_LINE_PRODUCT_ID) ?? self::text($line, self::F_LINE_PRODUCT_ID_ALT)) : null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        $byId = SfProduct::whereIn('sf_product_id', $ids)->get()->keyBy('sf_product_id');

        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $id = self::text($line, self::F_LINE_PRODUCT_ID) ?? self::text($line, self::F_LINE_PRODUCT_ID_ALT);
            $name = self::text($line, self::F_LINE_PRODUCT_NAME);

            $row = $id !== null ? $byId->get($id) : null;
            // ⚠ Idが来ていないとき（調査時のSOQLの形）だけ、括弧つきフルネームの完全一致で引く。
            //   フルネームは205件で重ならない（重なるのは括弧を外したとき＝D-3）。
            if ($row === null && $id === null && $name !== null) {
                $row = SfProduct::where('name', $name)->first();
            }

            $item = [
                'sf_id' => $id ?? $row?->sf_product_id,
                'name' => $name ?? $row?->name ?? ($id ?? '（名前のない商品）'),
                'handling' => 'unknown',
                'content_id' => null,
                'content_name' => null,
            ];
            if ($row) {
                $item['handling'] = $row->handling;
                if ($row->handling === SfProduct::CONTENT && $row->content_id) {
                    $content = Content::find($row->content_id);
                    if ($content) {
                        $item['content_id'] = $content->id;
                        $item['content_name'] = $content->content_name;
                    } else {
                        // つないだコンテンツが台帳から消えていたら、未対応あつかいに戻して目立たせる
                        $item['handling'] = SfProduct::PENDING;
                    }
                } elseif ($row->handling === SfProduct::CONTENT) {
                    $item['handling'] = SfProduct::PENDING;
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /** 担当営業のメール → 名簿の名前（1人に決まるときだけ）。 */
    public static function salesOwnerName(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }
        $hits = Person::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->pluck('name');

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /**
     * 受信箱の下見（ECSに入れるとこうなる）。画面と登録の両方がこれを使う。
     *
     * @return array<string, mixed>
     */
    public static function preview(array $payload): array
    {
        $warnings = [];

        $date = self::text($payload, self::F_DATE);
        if ($date !== null && ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)) {
            $warnings[] = "開催日が読めません（{$date}）。";
            $date = null;
        }

        $officeRaw = self::text($payload, self::F_OFFICE);
        $office = SfOffice::toEcs($officeRaw);

        $days = self::int($payload, self::F_DAYS);
        if ($days !== null && $days >= 2) {
            $warnings[] = "複数日です（{$days}日間）。人が分けてください。登録すると初日の1件だけ作ります。参加人数は全日の合計の可能性があります。";
        }

        $products = self::products($payload);
        $contents = array_values(array_filter($products, fn ($p) => $p['handling'] === SfProduct::CONTENT));
        $unmatched = array_values(array_filter($products, fn ($p) => in_array($p['handling'], [SfProduct::PENDING, 'unknown'], true)));
        if ($products === []) {
            $warnings[] = '商品（コンテンツ）が付いていません。';
        }

        $format = self::text($payload, self::F_FORMAT);
        if ($format !== null && ! in_array($format, ProjectFormats::ALL, true)) {
            $warnings[] = "実施形態「{$format}」はECSの選択肢にありません（空で入れます）。";
            $format = null;
        }

        $toolRaw = self::text($payload, self::F_ONLINE_TOOL);
        $tool = null;
        if ($toolRaw !== null) {
            $key = mb_strtolower($toolRaw);
            if (array_key_exists($key, self::ONLINE_TOOLS)) {
                $tool = self::ONLINE_TOOLS[$key];
            } else {
                // remo・その他など＝ECSに選択肢が無い。黙って空にせず知らせる（C-0a）
                $warnings[] = "オンラインツール「{$toolRaw}」はECSの選択肢にありません（空で入れます）。";
            }
        }

        $lodging = self::text($payload, self::F_LODGING);
        if ($lodging !== null && ! in_array($lodging, self::LODGINGS, true)) {
            $warnings[] = "宿泊「{$lodging}」はECSの選択肢にありません（空で入れます）。";
            $lodging = null;
        }

        $inout = self::text($payload, self::F_INOUT);
        $stage = self::text($payload, self::F_STAGE);
        $ownerEmail = self::text($payload, self::F_OWNER_EMAIL);

        return [
            'sf_id' => self::text($payload, self::F_ID),
            'sf_name' => self::text($payload, self::F_NAME),
            'stage' => $stage,
            'yomi' => $stage !== null ? (self::STAGE_TO_YOMI[$stage] ?? null) : null,
            'amount' => self::int($payload, self::F_AMOUNT),
            'date' => $date,
            'days' => $days,
            'client_raw' => self::text($payload, self::F_ACCOUNT),
            'client' => ClientName::normalize(self::text($payload, self::F_ACCOUNT)),
            'agency' => self::text($payload, self::F_AGENCY),
            'office_raw' => $officeRaw,
            'office' => $office,               // null＝要確認
            'guest_count' => self::int($payload, self::F_GUEST),
            'staff_count' => self::int($payload, self::F_STAFF),
            'scale' => in_array(self::text($payload, self::F_SCALE), ['小型', '中型', '大型'], true) ? self::text($payload, self::F_SCALE) : null,
            'format' => $format,
            'online_tool' => $tool,
            'location' => self::text($payload, self::F_VENUE),
            'team_count' => self::int($payload, self::F_TEAMS),
            'start_time' => self::text($payload, self::F_MEET),
            'end_time' => self::text($payload, self::F_LEAVE),
            'event_start_time' => self::text($payload, self::F_EVENT_START),
            'event_end_time' => self::text($payload, self::F_EVENT_END),
            'lodging' => $lodging,
            'is_outdoor' => $inout === '屋外' ? true : ($inout === '屋内' ? false : null),
            'note' => self::text($payload, self::F_NOTE),
            'owner_email' => $ownerEmail,
            'sales_owner' => self::salesOwnerName($ownerEmail),
            'products' => $products,       // 無視の商品も入っている（画面では出さない）
            'contents' => $contents,       // つながったコンテンツ
            'unmatched' => $unmatched,     // 未対応・対応表に無い商品（目立たせる）
            'warnings' => $warnings,
        ];
    }

    /**
     * 下見 → 案件（projects）に入れる中身。ID・拠点・状態は登録する側（SfInboxRegister）が足す。
     *
     * @param  array<string, mixed>  $p  preview() の結果
     * @return array<string, mixed>
     */
    public static function toProjectAttributes(array $p): array
    {
        $contentIds = array_values(array_unique(array_map(fn ($c) => $c['content_id'], $p['contents'])));
        $names = array_map(fn ($c) => $c['content_name'], $p['contents']);
        // 未対応の商品は、その案件だけの名前（単発）として残す＝黙って落とさない。台帳には足さない。
        foreach ($p['unmatched'] as $u) {
            $names[] = $u['name'];
        }
        $names = array_values(array_unique($names));

        // 備考：SF側の備考＋どの商談から来たか（あとでSFを探せるように）
        $noteLines = [];
        if ($p['note'] !== null) {
            $noteLines[] = $p['note'];
        }
        $noteLines[] = 'Salesforceの商談から登録：'.($p['sf_name'] ?? '').'（'.($p['sf_id'] ?? '').'）';

        return [
            'project_name' => $names !== [] ? implode('・', $names) : '（コンテンツ未定）',
            'content_ids' => $contentIds,
            'content_names' => $names,
            'yomi' => $p['yomi'],
            'scale' => $p['scale'],
            'client' => $p['client'],
            'agency' => $p['agency'],
            'sales_owners' => $p['sales_owner'] !== null ? [$p['sales_owner']] : null,
            'start_date' => $p['date'],
            'start_time' => $p['start_time'],
            'end_time' => $p['end_time'],
            'event_start_time' => $p['event_start_time'],
            'event_end_time' => $p['event_end_time'],
            'format' => $p['format'],
            'online_tool' => ($p['format'] !== null && str_contains($p['format'], 'オンライン')) ? $p['online_tool'] : null,
            'location' => $p['location'],
            'lodging' => $p['lodging'],
            'is_outdoor' => $p['is_outdoor'],
            'guest_count' => $p['guest_count'],
            'team_count' => $p['team_count'],
            'required_count' => $p['staff_count'],
            // 運営人数がSFに無いときは「仮（未定）」の印を立てる＝人が決めたら直す
            'count_tentative' => $p['staff_count'] === null,
            'note' => implode("\n", $noteLines),
        ];
    }
}
