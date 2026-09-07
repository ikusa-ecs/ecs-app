<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 拠点の名前を「北海道」→「札幌」に直す（2026-09-07 baba決定）。
 *
 * 【なぜ】
 * Salesforce連携の打ち合わせで、社内の正式な拠点名が
 * 「東京／大阪／名古屋／福岡／東北／札幌」であることが分かった（上長回答）。
 * ECSだけ「北海道」で持っていると、SFから来た「札幌」の案件が
 * どの拠点の一覧にも出てこない案件になってしまう。
 *
 * 【どこを直すか】
 * 拠点名は文字そのままで各テーブルに入っている（拠点IDでは持っていない）ので、
 * 拠点マスタ・案件・人・他拠点ヘルプ・拠点ごとの設定キーの5か所をまとめて書き換える。
 * ⚠ 都道府県の「北海道」（人の住所・会場の住所）は別物なので触らない。
 */
return new class extends Migration
{
    /** 旧名・新名の正本（下の down() でも使う）。 */
    private const OLD = '北海道';
    private const NEW = '札幌';

    public function up(): void
    {
        $this->rename(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->rename(self::NEW, self::OLD);
    }

    private function rename(string $from, string $to): void
    {
        // ① 拠点マスタ
        if (Schema::hasTable('offices')) {
            DB::table('offices')->where('name', $from)->update(['name' => $to]);
        }

        // ② 拠点名を文字で持っているテーブル（列がある場合だけ）
        foreach (['projects' => 'office', 'people' => 'office', 'project_shares' => 'office'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }

        // ③ 拠点ごとの設定（キーが「項目名:拠点名」の形＝App\Support\OfficeSettings）
        if (Schema::hasTable('settings')) {
            foreach (DB::table('settings')->get() as $row) {
                if (str_ends_with((string) $row->key, ':'.$from)) {
                    $newKey = substr((string) $row->key, 0, -strlen($from)).$to;
                    // 同じキーが既にあると重複するので、その場合は古い行を消す
                    if (DB::table('settings')->where('key', $newKey)->exists()) {
                        DB::table('settings')->where('key', $row->key)->delete();
                    } else {
                        DB::table('settings')->where('key', $row->key)->update(['key' => $newKey]);
                    }
                }
            }
        }
    }
};
