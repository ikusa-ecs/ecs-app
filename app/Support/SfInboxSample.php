<?php

namespace App\Support;

use App\Models\SfInboxItem;

/**
 * SFの受信箱の見本データ（2026-11-16）。SFにまだつないでいないので、画面を試すために使う。
 *
 * ⚠ 見本は見分けがつくようにしてある：
 *   ・商談Idが「006SAMPLE」で始まる／商談名とお客様名に【見本】が付く／is_sample=true。
 *   ・消すときは is_sample=true の行だけ消す（本物の商談には触らない）。
 *   ・見本から登録した案件は消さない（案件を消すのは案件一覧で人が行う）。
 * ⚠ 中身の形は SfOpportunityMap の説明と同じ（SFの項目名のまま）。
 */
final class SfInboxSample
{
    /** @return list<array<string, mixed>> */
    public static function payloads(): array
    {
        $line = fn (string $id, string $name) => [
            'Quantity' => 1,
            'Product2Id' => $id,
            'PricebookEntry' => ['Product2' => ['Id' => $id, 'Name' => $name]],
        ];

        return [
            [
                'Id' => '006SAMPLE000000001',
                'Name' => '【見本】株式会社サンプル商事__2027-01-20',
                'StageName' => 'Aヨミ',
                'Amount' => 1500000,
                'Account' => ['Name' => '【見本】株式会社サンプル商事'],
                'Owner' => ['Email' => 'sample-sales@example.com'],
                'kaisaibi__c' => '2027-01-20',
                'kaisainissu__c' => 1,
                'ninzu__c' => 80,
                'tantokyoten__c' => '東京オフィス',
                'uriagegata__c' => '小型',
                'dairiten__r' => null,
                'Videochat__c' => null,
                'Event_Format__c' => 'リアル',
                'Staff_Required__c' => 6,
                'Venue_Address__c' => '東京都千代田区丸の内1-1-1',
                'OpportunityLineItems' => ['totalSize' => 2, 'records' => [
                    $line('01tTL00000GeaGTYAZ', 'チャンバラ合戦（合戦・運動）'),
                    $line('01tTL00000GeaGjYAJ', 'エアー遊具（合戦・運動）'),
                ]],
            ],
            [
                'Id' => '006SAMPLE000000002',
                'Name' => '【見本】サンプル工業株式会社__2027-02-05',
                'StageName' => '受注',
                'Amount' => 4200000,
                'Account' => ['Name' => '【見本】サンプル工業株式会社 様'],
                'Owner' => ['Email' => 'sample-sales@example.com'],
                'kaisaibi__c' => '2027-02-05',
                'kaisainissu__c' => 2,
                'ninzu__c' => 300,
                'tantokyoten__c' => '大阪オフィス',
                'uriagegata__c' => '中型',
                'dairiten__r' => ['Name' => '【見本】サンプル代理店'],
                'Videochat__c' => null,
                'OpportunityLineItems' => ['totalSize' => 1, 'records' => [
                    $line('01tTL00000GeaGaYAJ', '戦国運動会（合戦・運動）'),
                ]],
            ],
            [
                'Id' => '006SAMPLE000000003',
                'Name' => '【見本】サンプル大学__2027-03-10',
                'StageName' => 'Bヨミ',
                'Amount' => 800000,
                'Account' => ['Name' => '【見本】サンプル大学'],
                'Owner' => ['Email' => 'sample-sales@example.com'],
                'kaisaibi__c' => '2027-03-10',
                'kaisainissu__c' => 1,
                'ninzu__c' => 45.0,
                'tantokyoten__c' => '東北・北海道オフィス',
                'uriagegata__c' => '小型',
                'Videochat__c' => 'teams',
                'Event_Format__c' => 'オンライン',
                'OpportunityLineItems' => ['totalSize' => 1, 'records' => [
                    $line('01tTL00000GeaH2YAJ', '25hunt（謎解き・ビジネス）'),
                ]],
            ],
            [
                'Id' => '006SAMPLE000000004',
                'Name' => '【見本】取引先未設定の商談',
                'StageName' => 'Aヨミ',
                'Amount' => null,
                'Account' => null,
                'Owner' => ['Email' => 'sample-sales@example.com'],
                'kaisaibi__c' => null,
                'kaisainissu__c' => null,
                'ninzu__c' => null,
                'tantokyoten__c' => null,
                'uriagegata__c' => null,
                'OpportunityLineItems' => null,
            ],
        ];
    }

    /** 見本を受信箱に入れる（同じIdがあれば入れ直さない）。@return int 入れた件数 */
    public static function seed(): int
    {
        $n = 0;
        foreach (self::payloads() as $payload) {
            $item = SfInboxItem::firstOrCreate(
                ['sf_opportunity_id' => $payload['Id']],
                [
                    'payload' => $payload,
                    'status' => SfInboxItem::WAITING,
                    'owner_email' => $payload['Owner']['Email'] ?? null,
                    'received_at' => now(),
                    'is_sample' => true,
                ]
            );
            if ($item->wasRecentlyCreated) {
                $n++;
            }
        }

        return $n;
    }

    /** 見本だけ消す。@return int 消した件数 */
    public static function clear(): int
    {
        return SfInboxItem::where('is_sample', true)->delete();
    }
}
