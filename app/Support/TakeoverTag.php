<?php

namespace App\Support;

/**
 * 「巻き取り」の札の文字を作る（正本）。
 *
 * 使う画面＝アサイン表（日付の下の札）・D決め（案件の2行目）。
 * ⚠ 画面ごとに言い方が食い違わないよう、文字はここだけで作る。
 *
 * ・巻き取りが無い案件 → 空文字（札を出さない）
 * ・他拠点の案件を、いま見ている拠点が巻き取った → 「大阪から巻き取り」
 * ・それ以外（自拠点の案件をよそが巻き取る など） → 「大阪が巻き取り」
 */
class TakeoverTag
{
    /**
     * @param  iterable  $shares  この案件の project_shares（モデルでも ['office'=>, 'kind'=>] の配列でもよい）
     * @param  string  $ownerOffice  案件の登録拠点
     * @param  string  $myOffice  いま見ている拠点
     */
    public static function of(iterable $shares, string $ownerOffice, string $myOffice): string
    {
        $take = collect($shares)
            ->map(fn ($s) => ['office' => (string) data_get($s, 'office'), 'kind' => (string) data_get($s, 'kind')])
            ->filter(fn ($s) => $s['kind'] === '巻き取り')
            ->values();
        if ($take->isEmpty()) {
            return '';
        }
        if ($ownerOffice !== $myOffice && $take->contains(fn ($s) => $s['office'] === $myOffice)) {
            return ($ownerOffice !== '' ? $ownerOffice.'から' : '').'巻き取り';
        }

        return $take->pluck('office')->filter()->implode('・').'が巻き取り';
    }
}
