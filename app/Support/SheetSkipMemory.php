<?php

namespace App\Support;

use App\Models\SheetImportSkip;

/**
 * 取込で「取り込まない」にした案件を覚える係（2026-10-21 baba「毎回すでにチェックしたことをチェック外すのめんどう」）。
 *
 * 【なぜ要るか】
 * ECSのほうが正しい案件（例：名古屋の人がECSにだけ入っている）は、取り込むたびに
 * 「✏️ 変わります」と出続けるので、毎回同じ案件のチェックを外していた。
 *
 * 【決まり】
 *   ・外して取り込んだとき、その案件の差分の指紋を覚える
 *   ・次の下見で**差分が同じ**なら、はじめからチェックを外しておく（🔕 前回も外した）
 *   ・差分が**変わった**ら、いつもどおりチェックを入れて見せる（見落としを防ぐ）
 *   ・その案件を取り込んだら、覚えたものは消す
 *
 * ⚠ 「ずっと外す」にはしない。アサイン表であとから時間などが直ったときに、黙って取り込まれないと気づけないため。
 * ⚠ 覚えるのは「✏️ 変わります」の案件だけ（新規はECSの案件IDが無い・変化なしは外しても覚える意味が無い）。
 */
final class SheetSkipMemory
{
    /**
     * 差分の指紋。人の並び順の違いでは変わらないようにそろえる。
     *
     * @param  array{changes?: array, people?: array}  $diff  SheetDiff::forCase の返り値
     */
    public static function fingerprint(array $diff): string
    {
        $people = [];
        foreach (($diff['people'] ?? []) as $kind => $list) {
            $list = array_map('strval', (array) $list);
            sort($list);
            $people[$kind] = $list;
        }
        ksort($people);

        return md5(json_encode([$diff['changes'] ?? [], $people], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 覚えている案件と指紋の一覧（下見で1回だけ読む）。
     *
     * @return array<string, string>  [案件ID => 指紋]
     */
    public static function all(): array
    {
        return SheetImportSkip::pluck('fingerprint', 'project_id')->map(fn ($v) => (string) $v)->all();
    }

    /** 外して取り込んだ＝この差分を覚える（同じ案件は上書き）。 */
    public static function remember(string $projectId, string $fingerprint, ?string $by): void
    {
        SheetImportSkip::updateOrCreate(
            ['project_id' => $projectId],
            ['fingerprint' => $fingerprint, 'skipped_by' => $by]
        );
    }

    /** 取り込んだ＝もう外していないので忘れる。 */
    public static function forget(string $projectId): void
    {
        SheetImportSkip::where('project_id', $projectId)->delete();
    }
}
