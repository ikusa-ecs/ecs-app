<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 人ごと・その日ごとのメモ（2026-09-02 baba要望／2026-09-03 「その日だけ」に変更）。
 *
 * D決め画面で社員名を押したときのふきだしに書く、その人のその日についてのメモ。
 * 例：10/3 のところに「大型入ってるからアサインしない」
 *
 * ⚠ **1人×1日で1行**（person_id + date に unique）。日付を持たないと、
 *   カレンダーの全部の日に同じメモが出てしまう（2026-09-03 baba報告で判明）。
 * ⚠ 読み書きは App\Support\PersonNotes を通す（画面ごとに書き方を持たない）。
 */
class PersonNote extends Model
{
    protected $guarded = [];
}
