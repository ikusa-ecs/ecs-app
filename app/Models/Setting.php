<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * アプリ全体の小さな設定（key/value）。
 * スタッフ画面のお知らせ文（key='staff_notice'）などに使う。
 */
class Setting extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    /** 設定値を取り出す（無ければ $default を返す）。 */
    public static function get(string $key, $default = null)
    {
        $row = static::find($key);

        return $row ? $row->value : $default;
    }

    /** 設定値を保存する（あれば上書き）。 */
    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
