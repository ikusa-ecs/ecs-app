<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 人ごとのメモ（2026-09-02 baba要望）。
 *
 * D決め画面で社員名を押したときのふきだしに書く、その人あてのメモ。
 * 例：「10/3 大型入ってるからアサインしない」
 *
 * ⚠ 1人1行（person_id に unique）。読み書きは App\Support\PersonNotes を通す
 *   （画面ごとに書き方を持たない）。
 */
class PersonNote extends Model
{
    protected $guarded = [];
}
