<?php

namespace App\Support;

/**
 * 運営人数が空のときに「仮の人数」を出す正本（2026-09-08 baba要望）。
 *
 * 【babaの言葉】「CSVから案件登録するときに運営人数が空白だったら、
 *   人数とコンテンツで判断して仮で運営人数を算出するようにしてほしい。
 *   今0になってるから最少人数5にしてほしい。」
 *
 * 【なぜ要るか】
 * 運営人数が空のままだと、アプリ中の「必要◯名／あと◯名」がぜんぶ **0** になる。
 * ＝日別ボードで「足りている」ように見え、自動アサインの対象からも外れる（`needOf` が0を
 *   「未入力」として飛ばすため）。**気づかないまま人が足りない**のが一番こわい。
 * ⇒ 空のときは、分かっている材料（参加人数・コンテンツ）から**仮**の数を置いておく。
 *
 * 【出し方（上から順に試す）】
 *  ① コンテンツと規模が分かる → **必要アサイン人数の合計**（コンテンツ×規模。マスタの実数）
 *  ② 規模が空なら、**参加人数から規模を決める**（下の SCALE_BY_GUESTS）
 *  ③ どちらも分からない → **最少の5名**
 *  最後に必ず「5名より少なくしない」。
 *
 * ⚠ **これは仮の数**。入れるときは `projects.count_tentative`（運営人数は仮）を必ず立てる。
 *   画面に「仮」と出て、あとで人が本当の数を入れられるようにするため。
 *   ⚠ 勝手に「確定の数」として置くと、セールスが入れた数と見分けが付かなくなる。
 */
final class RequiredCountEstimate
{
    /**
     * これより少なくしない人数（2026-09-08 baba指定）。
     * ⚠ スタッフ画面が運営人数未入力のときに「5名」と見せているのと同じ数にそろえている
     *   （画面によって違う数が出ると、どちらが本当か分からなくなる）。
     */
    public const MINIMUM = 5;

    /**
     * 参加人数 → 案件規模。
     *
     * ⚠ この区切りは**私たちが決めたものではない**＝「必要アサイン人数リスト」（実物）の
     *   参加人数の3段階そのもの（～49名／50～99名／100～150名）。
     *   正本の説明は App\Support\RoleRequirementCsv の冒頭にある。
     *   ⇒ リストの区切りが変わったら、ここも一緒に直す（テストで見張っている）。
     */
    private const SCALE_BY_GUESTS = [
        ['upTo' => 49, 'scale' => '小型'],
        ['upTo' => 99, 'scale' => '中型'],
        // 100名以上は大型（リストの上限は150名だが、それ以上も大型として扱う）。
    ];

    private const LARGE = '大型';

    /**
     * 参加人数から規模を決める。分からない（未入力・0以下）は null。
     */
    public static function scaleFromGuests(?int $guests): ?string
    {
        if ($guests === null || $guests <= 0) {
            return null;
        }

        foreach (self::SCALE_BY_GUESTS as $step) {
            if ($guests <= $step['upTo']) {
                return $step['scale'];
            }
        }

        return self::LARGE;
    }

    /**
     * 仮の運営人数を出す。
     *
     * @param  array<int, string>  $contentIds  コンテンツID（案件が持つもの）
     * @param  string|null  $scale   案件規模（入っていればそれを使う）
     * @param  int|null  $guests    参加人数（お客様人数）
     * @return array{count: int, scale: ?string, fromTemplate: bool, reason: string}
     *         count ＝入れる人数／scale ＝判断に使った規模／
     *         fromTemplate ＝コンテンツの必要人数から出せたか／reason ＝人に見せる説明
     */
    public static function for(array $contentIds, ?string $scale, ?int $guests): array
    {
        // 規模＝入っていればそのまま。空なら参加人数から決める。
        $usedScale = in_array((string) $scale, RoleRequirementCsv::SCALES, true)
            ? (string) $scale
            : self::scaleFromGuests($guests);

        $template = PositionTemplate::forContents($contentIds, $usedScale);
        $sum = array_sum($template);

        if ($sum > 0) {
            $count = max($sum, self::MINIMUM);
            $reason = 'コンテンツの必要アサイン人数（'.$usedScale.'）の合計 '.$sum.'名';
            if ($count !== $sum) {
                $reason .= '（最少の'.self::MINIMUM.'名に引き上げ）';
            }
            if (! in_array((string) $scale, RoleRequirementCsv::SCALES, true) && $usedScale !== null) {
                $reason .= '／規模は参加人数'.$guests.'名から「'.$usedScale.'」と判断';
            }

            return ['count' => $count, 'scale' => $usedScale, 'fromTemplate' => true, 'reason' => $reason];
        }

        // コンテンツの必要人数が登録されていない（または規模が分からない）＝最少で置く。
        $why = $usedScale === null
            ? '参加人数も案件規模も分からないため'
            : 'このコンテンツの必要アサイン人数（'.$usedScale.'）が未登録のため';

        return [
            'count' => self::MINIMUM,
            'scale' => $usedScale,
            'fromTemplate' => false,
            'reason' => $why.'、最少の'.self::MINIMUM.'名',
        ];
    }
}
