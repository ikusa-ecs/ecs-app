<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 実施形態(format)から「拠点」と「他拠点のやり取り」を切り離す（全拠点運用・設計書19.2／B案）。
 *
 * これまで format は「イベント東(リアル)」「イベント東北(リアル)」のように拠点も含んでいた。
 * 拠点は projects.office（登録拠点）に持たせたので、format は形態だけ
 * （リアル／リアルロング／オンライン／ARENA場所貸し／体験会）に簡素化する。
 *
 *  ・office ＝ 古い format から正しく判定し直す（Phase1で一括「東京」にしたが、東北案件は東北へ）。
 *  ・format ＝ 形態だけに変換。
 *  ※ データ変換なので down() では元の文字列に戻せない（no-op）。
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('projects')->get(['id', 'format', 'office']) as $p) {
            $f = (string) ($p->format ?? '');
            if ($f === '') {
                continue;   // 実施形態が空の案件はそのまま（拠点は Phase1 の東京のまま）
            }

            // ① 拠点を古い実施形態から判定し直す（東北だけ補正。他は Phase1 の東京を維持）。
            $office = $p->office;
            if (str_contains($f, '東北')) {
                $office = '東北';
            }

            // ② 実施形態を「形態だけ」に簡素化する。
            $format = match (true) {
                str_contains($f, 'ロング')                          => 'リアルロング',
                str_contains($f, 'オンライン')                      => 'オンライン',
                str_contains($f, 'ARENA') || str_contains($f, '場所貸し') => 'ARENA場所貸し',
                str_contains($f, '体験')                            => '体験会',
                // 「他拠点」「ヘルプ」などの拠点間のやり取りは project_shares 側で持つ。形態はリアル扱い。
                default                                            => 'リアル',
            };

            DB::table('projects')->where('id', $p->id)->update([
                'office' => $office,
                'format' => $format,
            ]);
        }
    }

    public function down(): void
    {
        // データ変換のため元の文字列には戻せない（意図的に no-op）。
    }
};
