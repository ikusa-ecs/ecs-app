<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 「スタッフに伝えること」を1つの記入欄にまとめる（2026-08-21 baba）。
 *
 * これまで＝集合場所の詳細／服装／持ち物／当日の注意事項の4欄。
 * 実際は備考のように自由に書きたいだけなので、1欄（staff_notes）に統合する。
 *
 * ここでやること＝すでに入っている4欄の内容を staff_notes にまとめ直す（消さない）。
 * 見出しを付けて並べるので、どれが何だったか分かる形で残る。
 * ※ 元の3列（assembly_detail / staff_belongings / staff_dresscode）は消さずに残す。
 *   画面からは使わなくなるが、万一の取り違えに備えて中身を保持しておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('projects')
            ->select('id', 'assembly_detail', 'staff_belongings', 'staff_dresscode', 'staff_notes')
            ->get();

        foreach ($rows as $r) {
            $parts = [];
            if (trim((string) $r->assembly_detail) !== '') {
                $parts[] = '集合場所の詳細：' . trim($r->assembly_detail);
            }
            if (trim((string) $r->staff_dresscode) !== '') {
                $parts[] = '服装：' . trim($r->staff_dresscode);
            }
            if (trim((string) $r->staff_belongings) !== '') {
                $parts[] = '持ち物：' . trim($r->staff_belongings);
            }
            if (trim((string) $r->staff_notes) !== '') {
                $parts[] = trim($r->staff_notes);
            }

            // まとめる中身が無い、または注意事項しか無い（＝すでに1欄）ときは触らない。
            if (count($parts) <= 1) {
                continue;
            }

            DB::table('projects')->where('id', $r->id)->update([
                'staff_notes' => implode("\n", $parts),
            ]);
        }
    }

    public function down(): void
    {
        // まとめた文章を機械的に4欄へ戻すことはできないため、何もしない
        // （元の3列は消していないので、そちらの値はそのまま残っている）。
    }
};
