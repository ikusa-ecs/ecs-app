<?php

namespace Tests\Feature;

use App\Models\SfProduct;
use App\Support\SfProductCsv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SFの「商品」レポートを日本語見出しのまま出したCSV（2026-11-20）が取り込めること。
 * 実ファイルには頼らず、同じ見出しの小さなCSVで確かめる。
 */
class SfProductCsvJpHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = '"商品ファミリー","商品名","商品ID18桁","有効(商品)","商品: 作成日","商品: 作成者(別名)","商品: 最終更新日","商品: 最終更新者(別名)"';

    private function csv(): string
    {
        return self::HEAD."\n"
            .'"合戦・運動","ミッションラッシュ（合戦・運動）","01tTL00000RlIuoYAF","1","2025/08/28","中村","2026/05/15","中村"'."\n"
            .'"あそ研","旧商品（あそ研）","01tTL00000AAAAAAAA","0","2025/08/28","中村","2026/05/15","佐藤"'."\n";
    }

    public function test_日本語見出しのCSVが読める(): void
    {
        $r = SfProductCsv::import($this->csv());
        $this->assertSame(2, $r['created']);
        $this->assertSame([], $r['skipped']);

        $a = SfProduct::where('sf_product_id', '01tTL00000RlIuoYAF')->first();
        $this->assertSame('合戦・運動', $a->family);
        $this->assertTrue((bool) $a->is_active);
        $this->assertNull($a->hint); // 更新者名を候補メモにしない

        $b = SfProduct::where('sf_product_id', '01tTL00000AAAAAAAA')->first();
        $this->assertFalse((bool) $b->is_active);
        $this->assertTrue((bool) $b->with_asoken);
    }

    public function test_BOM付きとShiftJISでも読める(): void
    {
        $this->assertSame(2, SfProductCsv::import("\xEF\xBB\xBF".$this->csv())['created']);
        SfProduct::query()->delete();
        $sjis = mb_convert_encoding($this->csv(), 'SJIS-win', 'UTF-8');
        $this->assertSame(2, SfProductCsv::import($sjis)['created']);
    }

    public function test_取り込み直しても人の仕分けは消えない(): void
    {
        SfProductCsv::import($this->csv());
        SfProduct::where('sf_product_id', '01tTL00000RlIuoYAF')->update(['handling' => SfProduct::IGNORE, 'note' => '機材']);

        $r = SfProductCsv::import($this->csv());
        $this->assertSame(2, $r['updated']);
        $p = SfProduct::where('sf_product_id', '01tTL00000RlIuoYAF')->first();
        $this->assertSame(SfProduct::IGNORE, $p->handling);
        $this->assertSame('機材', $p->note);
    }

    public function test_従来の英語見出しも読める(): void
    {
        $r = SfProductCsv::import("Id,Name,ProductCode,Family,IsActive\n01tTL00000BBBBBBBB,テスト,,謎解き・ビジネス,TRUE\n");
        $this->assertSame(1, $r['created']);
    }
}
