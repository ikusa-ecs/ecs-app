<?php

namespace App\Support;

/**
 * 拠点間の関わり（ヘルプ／巻き取り）を画面に出すときの札の文言の正本。2026-09-18。
 *
 * 【なぜ要るか】
 * ⚠ 拠点の関わりは `project_shares` に1行だけ残す作りで、案件は複製しない（設計書19.2）。
 *   そのため**札を出さないと、画面上はふつうの自拠点案件と見分けが付かない**。
 *   実際に「巻き取りにしたのに案件一覧でふつうに並んでいる」「他拠点から来た案件だと分からない」
 *   という指摘が続けて出た（2026-09-18 baba）。
 *
 * 【言い方が2種類あるのはわざと】
 *   ・案件一覧    … 「名古屋巻き取り」（2026-09-18 baba の指定どおりの言い方）
 *   ・日別ボード  … 「名古屋からヘルプ」「名古屋に巻き取り」＝**向き**が分かる言い方。
 *     ボードには「自拠点の案件」と「他拠点から来た案件」が混ざって並ぶので、
 *     どちらから来た関わりなのかが分からないと取り違える。
 * ⚠ 言い方を変えるときはこのファイルだけ。画面（Blade）で文字をつなげない。
 */
final class ShareTags
{
    /** 案件一覧の札＝「名古屋巻き取り」。 */
    public static function short(string $office, string $kind): string
    {
        return $office.$kind;
    }

    /** 日別ボードの札＝「名古屋からヘルプ」（相手の拠点の案件を、こちらが手伝う／引き取る）。 */
    public static function fromOwner(string $owner, string $kind): string
    {
        return $owner.'から'.$kind;
    }

    /** 日別ボードの札＝「名古屋にヘルプ」（こちらの案件を、相手の拠点に手伝ってもらう）。 */
    public static function toOffice(string $office, string $kind): string
    {
        return $office.'に'.$kind;
    }

    /**
     * 見ている拠点から見た札の一覧（日別ボード用）。
     *
     * @param  string|null  $ownerOffice  案件の登録拠点
     * @param  iterable  $shares  その案件の project_shares（office / kind を持つもの）
     * @param  string|null  $scope  いま見ている拠点（null＝全拠点表示）
     * @return array<int, array{label: string, kind: string}>
     */
    public static function forProject(?string $ownerOffice, iterable $shares, ?string $scope): array
    {
        $owner = trim((string) $ownerOffice);
        if ($owner === '') {
            // ⚠ 拠点が空の案件は東京あつかい（名簿・案件と同じ決まり＝OfficeScope と揃える）。
            $owner = OfficeScope::DEFAULT_OFFICE;
        }

        $rows = [];
        foreach ($shares as $s) {
            $office = trim((string) ($s->office ?? ''));
            $kind = trim((string) ($s->kind ?? ''));
            if ($office === '' || $kind === '') {
                continue;
            }
            $rows[] = ['office' => $office, 'kind' => $kind];
        }

        if ($rows === []) {
            return [];
        }

        $tags = [];

        // ① 他拠点の案件を、いま見ている拠点が手伝う／引き取っている＝「名古屋からヘルプ」
        if ($scope !== null && $owner !== $scope) {
            foreach ($rows as $r) {
                if ($r['office'] === $scope) {
                    $tags[] = ['label' => self::fromOwner($owner, $r['kind']), 'kind' => $r['kind']];
                }
            }
        }

        // ② こちらの案件を、他拠点に手伝ってもらっている＝「名古屋にヘルプ」
        //    （全拠点表示のときは、どの関わりもこの形で出す）
        foreach ($rows as $r) {
            if ($scope !== null && $r['office'] === $scope) {
                continue;   // 自分の拠点ぶんは①で出している
            }
            $tags[] = ['label' => self::toOffice($r['office'], $r['kind']), 'kind' => $r['kind']];
        }

        return $tags;
    }
}
