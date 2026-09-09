<?php

namespace App\Support;

/**
 * 案件を「小型／中型／大型」のどれとして数えるかの正本（2026-09-09 上長要望）。
 *
 * 【なぜ要るか】
 * 集計ダッシュボードの主役を「リアル／オンライン」から「小型／中型／大型」に変えた。
 * ところが案件の「案件規模」は**空のことがある**（アサイン表から取り込んだ案件など）。
 * 空をどこにも数えないと **小型＋中型＋大型 ≠ 合計** になり、見る人が数字を信じられなくなる。
 *
 * 【いまの決まり】空欄は「小型」に数える（2026-09-09 baba。上長に確認中の暫定）。
 * ⚠ **変えるときはこのファイルの UNSET_GOES_TO の1行だけ**を直すこと。
 *   画面やコントローラに「空なら小型」と書き写さない（書き写すと片方だけ直して食い違う）。
 * ⚠ 空欄を混ぜて数えていることは、必ず画面に出す（isUnset で数えて「うち規模未入力◯件」と添える）。
 *   黙って小型に混ぜると、小型が多いのか入力漏れが多いのか誰にも分からなくなる。
 *
 * 【規模の言葉そのもの】は App\Support\RoleRequirementCsv::SCALES が正本
 * （必要アサイン人数リストの区切り＝～49名 小型／50～99名 中型／100名以上 大型）。
 * ここではその並び順（小さい順）をそのまま使う。
 */
final class ProjectScale
{
    /**
     * 「案件規模」が空（またはこの3つ以外）の案件を、どれに数えるか。
     * ⚠ ここを null にすると「未設定」を別枠にできる。決め直したらこの1行だけ直す。
     */
    public const UNSET_GOES_TO = '小型';

    /** 表示・集計の並び（小さい順）。正本＝RoleRequirementCsv::SCALES。 */
    public static function all(): array
    {
        return RoleRequirementCsv::SCALES;
    }

    /** その案件を数える規模。空欄・知らない書き方は UNSET_GOES_TO へ。 */
    public static function of(?string $scale): string
    {
        $s = trim((string) $scale);

        return in_array($s, self::all(), true) ? $s : self::UNSET_GOES_TO;
    }

    /**
     * 「案件規模」が入っていない（または知らない書き方）か。
     * ⚠ 画面の注記（うち規模未入力◯件）に使う。数える／数えないの判断には使わない。
     */
    public static function isUnset(?string $scale): bool
    {
        return ! in_array(trim((string) $scale), self::all(), true);
    }
}
