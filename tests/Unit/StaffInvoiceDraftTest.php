<?php

namespace Tests\Unit;

use App\Support\StaffInvoiceDraft;
use PHPUnit\Framework\TestCase;

/**
 * 請求書の目安（マニュアル「請求書作成（26年5月～）」の書き方・標準単価）。2026-10-13。
 */
class StaffInvoiceDraftTest extends TestCase
{
    private function c(string $ymd, array $o = []): array
    {
        return $o + ['ymd' => $ymd, 'name' => '戦国運動会', 'format' => 'リアル', 'roleCode' => 'FC',
            'dayType' => '本番', 'lodging' => '', 'cancelled' => false, 'status' => '確定'];
    }

    public function test_items_follow_the_manual(): void
    {
        $d = StaffInvoiceDraft::build([
            $this->c('2026-10-05', ['roleCode' => 'MC']),
            $this->c('2026-10-12'),
            $this->c('2026-10-18', ['format' => 'リアルロング', 'lodging' => '前泊有']),
            // はしご＝同じ日に2案件（リアル）
            $this->c('2026-10-20', ['roleCode' => 'MC']),
            $this->c('2026-10-20', ['roleCode' => 'MC', 'name' => '謎パ']),
            $this->c('2026-10-25', ['format' => 'オンライン']),
            $this->c('2026-10-10', ['dayType' => 'リハ日', 'name' => '鷹狩り']),
            $this->c('2026-10-30', ['dayType' => '前日設営']),
            $this->c('2026-10-28', ['cancelled' => true, 'name' => '水合戦']),
        ], '新宿');

        $labels = array_column($d['items'], 'label');
        $this->assertContains('オンラインイベント：10/25', $labels);
        $this->assertContains('リアルイベント：10/5、12、18', $labels, '同じ月は日だけ続ける・中止とリハ日・前日設営は入れない');
        $this->assertContains('リアルイベント2案件/日：10/20', $labels);
        $this->assertContains('MC手当：10/5、10/20(2案件)', $labels);
        $this->assertContains('ロング手当：10/18', $labels);
        $this->assertContains('前日設営手当：10/30', $labels);
        $this->assertContains('事前リハーサル手当：10/10（鷹狩りリハーサル）', $labels);
        $this->assertContains('前泊手当：10/17（10/18本番の戦国運動会のため）', $labels);

        $byLabel = array_column($d['items'], null, 'label');
        $this->assertSame(27000, $byLabel['リアルイベント：10/5、12、18']['amount']);
        $this->assertSame(13500, $byLabel['リアルイベント2案件/日：10/20']['price'], '2案件/日＝×1.5');
        $this->assertSame(3000, $byLabel['MC手当：10/5、10/20(2案件)']['amount']);

        $checks = implode("\n", $d['checks']);
        $this->assertStringContainsString('最寄り駅「新宿」', $checks);
        $this->assertStringContainsString('キャンセル料の対象かも：10/28 水合戦', $checks);
    }
}
