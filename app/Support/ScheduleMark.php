<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * コンテンツ名に書かれた「リハ」「予備」などの印から、日程種別を読み取る正本。
 * 2026-09-15 baba要望（アサイン表の取り込みで、リハが新しい案件として入ってしまう）。
 *
 * 【babaの言葉】「リハはコンテンツ名にリハって書いてあることが多い」
 *   実例＝`綱引き大会(リハ)` / `鷹狩りリハーサル`
 *
 * 【なぜ要るか】
 * 月ごとのアサイン表には「日程種別」の欄がない。種別はコンテンツ名に書かれている。
 * これまでは名前をそのまま案件名にしていたので、
 *   ① リハが「本番」として入る（回数にも数えてしまう）
 *   ② 本番と紐づかない（別々の案件に見える）
 *   ③ **コンテンツ台帳に「綱引き大会(リハ)」という新しいコンテンツが増える**
 *      （名前が一致しないため、取り込みが勝手に作ってしまう）
 * の3つが起きていた。
 *
 * ⚠ **印を増やすときは MARKS に1行足すだけ。** 画面や取り込みに書き写さない。
 * ⚠ 紐づけ先が決められないときは**紐づけない**（{@see findParent()} が null を返す）。
 *   勘で紐づけると、間違った本番にぶら下がって誰も気づけない。
 */
final class ScheduleMark
{
    /**
     * コンテンツ名に書かれる印 → 日程種別。
     * ⚠ 長いものから順に見る（「リハーサル」を「リハ」より先に当てる）。
     * ⚠ 値は案件登録フォームで選べる種別と同じ文字にすること（別の文字だと画面の分岐から漏れる）。
     */
    private const MARKS = [
        'リハーサル' => 'リハ日',
        'リハ' => 'リハ日',
        '前日設営' => '前日設営',
        '設営' => '前日設営',
        '予備日' => '予備日',
        '予備' => '予備日',
    ];

    /**
     * 印に見えるが違うもの（ここに書いたものは印として扱わない）。
     * ⚠ 「リハビリ」を「リハ」と読み違えないため。
     */
    private const NOT_MARKS = ['リハビリ'];

    /** 本番を探す範囲（前後何日ぶんを見るか）。リハは本番の直前・数日前のことが多い。 */
    public const SEARCH_DAYS = 45;

    /**
     * コンテンツ名から印を読み取る。
     *
     * @return array{kind: string, clean: string, mark: string}|null 印が無ければ null
     */
    public static function detect(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        foreach (self::NOT_MARKS as $ng) {
            if (mb_strpos($name, $ng) !== false) {
                return null;
            }
        }

        foreach (self::MARKS as $mark => $kind) {
            if (mb_strpos($name, $mark) === false) {
                continue;
            }

            $clean = self::strip($name, $mark);
            // 印を外したら何も残らない名前（「リハ」だけ）は、コンテンツが分からないので印にしない。
            if ($clean === '') {
                return null;
            }

            return ['kind' => $kind, 'clean' => $clean, 'mark' => $mark];
        }

        return null;
    }

    /**
     * 印を外した名前。`綱引き大会(リハ)` → `綱引き大会` / `鷹狩りリハーサル` → `鷹狩り`
     *
     * ⚠ 印を囲んでいる かっこ ごと外す（全角・半角・【】の3種類）。
     *   かっこだけ残ると `綱引き大会()` になって、これも台帳と一致しない。
     */
    private static function strip(string $name, string $mark): string
    {
        $m = preg_quote($mark, '/');
        // ① かっこで囲まれている形
        $out = preg_replace('/[（(\[【]\s*'.$m.'\s*[）)\]】]/u', '', $name);
        // ② 裸で付いている形
        $out = str_replace($mark, '', (string) $out);

        // 余った区切り文字と空白を落とす。
        $out = preg_replace('/^[\s　_\-ー・]+|[\s　_\-ー・]+$/u', '', (string) $out);

        return trim((string) $out);
    }

    /**
     * その「リハ・予備日・前日設営」が、どの本番にぶら下がるかを探す。
     *
     * 探し方＝**同じ拠点・同じお客様・同じコンテンツ名の「本番」で、日付がいちばん近いもの**。
     *
     * ⚠ **1件に決められないときは null を返す**（0件でも、2件以上でも）。
     *   勘で紐づけると、間違った本番にぶら下がって誰も気づけない。
     *   呼ぶ側は null のときに「手で紐づけてください」と知らせること。
     *
     * @param  string  $cleanName  印を外したコンテンツ名
     * @param  string  $date       その日（Y-m-d）
     */
    public static function findParent(string $cleanName, ?string $client, string $office, string $date, ?string $excludeId = null): ?Project
    {
        $cleanName = trim($cleanName);
        if ($cleanName === '' || $date === '') {
            return null;
        }

        $day = Carbon::parse($date)->startOfDay();

        $found = Project::query()
            ->where('date_type', '本番')
            ->where('project_name', $cleanName)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->when($office !== '', fn ($q) => $q->where('office', $office))
            // お客様名は「空の案件」もあるので、入っているときだけ突き合わせる。
            ->when(trim((string) $client) !== '', fn ($q) => $q->where('client', trim((string) $client)))
            ->whereBetween('start_date', [
                $day->copy()->subDays(self::SEARCH_DAYS)->format('Y-m-d'),
                $day->copy()->addDays(self::SEARCH_DAYS)->format('Y-m-d'),
            ])
            ->get();

        if ($found->isEmpty()) {
            return null;
        }
        if ($found->count() === 1) {
            return $found->first();
        }

        // 2件以上あるときは、日付がいちばん近いものを選ぶ。
        // ⚠ ただし**同じ近さのものが複数あるときは決めない**（どちらが正しいか分からないため）。
        $byDistance = $found->sortBy(fn (Project $p) => abs(
            Carbon::parse($p->start_date)->startOfDay()->diffInDays($day)
        ))->values();

        $nearest = abs(Carbon::parse($byDistance[0]->start_date)->startOfDay()->diffInDays($day));
        $tied = $byDistance->filter(fn (Project $p) => abs(
            Carbon::parse($p->start_date)->startOfDay()->diffInDays($day)
        ) === $nearest);

        return $tied->count() === 1 ? $byDistance[0] : null;
    }
}
