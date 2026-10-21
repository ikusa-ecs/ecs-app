<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * アサイン表の取込で「取り込まない」にした案件（テーブル sheet_import_skips・2026-10-21）。
 * 覚え方・照らし方の正本＝App\Support\SheetSkipMemory。
 */
class SheetImportSkip extends Model
{
    protected $guarded = [];
}
