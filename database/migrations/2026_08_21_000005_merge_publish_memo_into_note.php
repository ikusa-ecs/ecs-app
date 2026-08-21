<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 「備考」を1つにまとめる（2026-08-21 baba）。
 *
 * これまで＝案件登録の備考（projects.note）と、公開ボードの担当メモ（projects.publish_memo）の2つ。
 * 同じ「備考」という名前で中身が別のため、「案件登録で書いたのに公開ボードに出ない」と混乱した。
 * これからは note 1つに統一し、公開ボードからも同じ note を直す。
 *
 * ここでやること＝ publish_memo に入っている内容を note の末尾に足す（消さない）。
 * ※ publish_memo の列は残す（万一の取り違えに備えて中身を保持）。画面からは使わなくなる。
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('projects')
            ->select('id', 'note', 'publish_memo')
            ->whereNotNull('publish_memo')
            ->get();

        foreach ($rows as $r) {
            $memo = trim((string) $r->publish_memo);
            $note = trim((string) $r->note);

            if ($memo === '' || $note === $memo) {
                continue;
            }

            DB::table('projects')->where('id', $r->id)->update([
                'note' => $note === '' ? $memo : ($note . "\n" . $memo),
            ]);
        }
    }

    public function down(): void
    {
        // まとめた文章を機械的に分けることはできないため、何もしない
        // （publish_memo は消していないので、そちらの値はそのまま残っている）。
    }
};
