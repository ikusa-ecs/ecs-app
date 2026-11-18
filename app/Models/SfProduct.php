<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SFの商品 ↔ ECSのコンテンツの対応表。テーブルは sf_products（2026-11-16）。
 * 鍵は商品Id（sf_product_id・18桁）。名前は表示用。
 */
class SfProduct extends Model
{
    protected $table = 'sf_products';

    protected $guarded = [];

    /** 扱い：未対応（まだ決めていない）。 */
    public const PENDING = 'pending';

    /** 扱い：ECSのコンテンツにつなぐ。 */
    public const CONTENT = 'content';

    /** 扱い：無視する（オプション・機材・割引などコンテンツではないもの）。 */
    public const IGNORE = 'ignore';

    /** 画面に出す言葉。 */
    public const LABELS = [
        self::PENDING => '未対応',
        self::CONTENT => 'コンテンツ',
        self::IGNORE => '無視する',
    ];

    /**
     * 「あそ研も一緒に付ける」（with_asoken）で足すコンテンツの名前と、はじめから印を付ける商品ファミリ。
     * SFの「〇〇（あそ研）」は、ECSでは「〇〇」＋「あそ研」の2コンテンツにする（2026-11-18 小沼さん決定）。
     * 「〇〇（あそ研）」という別のコンテンツは台帳に作らない。
     */
    public const ASOKEN = 'あそ研';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'with_asoken' => 'boolean',
        ];
    }
}
