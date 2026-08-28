<?php

namespace App\Support;

use App\Models\Project;

/**
 * 「スタッフの画面にどう見えているか」の正本（2026-08-28 baba要望）。
 *
 * 【なぜ要るか】
 * 日別ボードは「公開しているか（staff_published）」だけを見て「募集中」と出していたが、
 * ⚠ **スタッフの画面では、人数が埋まった案件は「締切・満員」になっていてエントリーできない**。
 *   同じ案件が、社員から見ると「募集中」、スタッフから見ると「締切」でズレていた。
 *   どちらが本当かを1か所で決めて、両方の画面が同じことを言うようにする。
 *
 * 【決まり】
 *  ・満員＝**「確定」のアサインの人数が運営人数に達している**（`filled >= need`）。
 *    ⚠ **「仮」は数えない**（2026-08-28 baba決定）。仮＝まだ声掛け中で決まっていないため。
 *    以前は仮も数えていたので、アサイン表を取り込んでシートのメンバーが仮で入った瞬間に
 *    「締切・満員」になり、**スタッフがエントリーできなくなっていた**。
 *  ・⚠ 運営人数が未入力（空・0）のときは 5名 として扱う。
 *    そうしないと「0名必要」＝いつでも満員になり、公開した瞬間にエントリーできなくなる
 *    （2026-08-20 baba：人数はセールスが入れるが、未入力のまま公開されることがある）。
 *  ・満員でも**エントリー自体は締め切らない**（画面の見た目だけ）。
 *  ・⚠ この状態はどこにも保存しない。**運営人数を増やせば、その場でまた「募集中」に戻る**
 *    ＝追加募集のために公開し直す必要はない。
 */
final class RecruitStatus
{
    /**
     * 運営人数が未入力（空・0）のときに、スタッフ画面で使う人数。
     * ⚠ ここを 0 にしてはいけない（公開した瞬間に全部「満員」になる）。
     */
    public const DEFAULT_NEED = 5;

    /** スタッフ画面で使う「必要人数」。未入力なら既定の人数。 */
    public static function need(?int $requiredCount): int
    {
        return ((int) $requiredCount) > 0 ? (int) $requiredCount : self::DEFAULT_NEED;
    }

    /** その案件が、スタッフの画面で「締切・満員」に見えているか。 */
    public static function isFull(?int $requiredCount, int $filled): bool
    {
        return $filled >= self::need($requiredCount);
    }

    /** あと何名入れるか（満員なら 0）。 */
    public static function remaining(?int $requiredCount, int $filled): int
    {
        return max(0, self::need($requiredCount) - $filled);
    }

    /**
     * 社員側の画面に出す短い言葉。
     * ⚠ 公開していない案件は空文字（募集にまつわる言葉を出さない）。
     */
    public static function label(Project $project, int $filled): string
    {
        if (! $project->staff_published) {
            return '';
        }

        return self::isFull($project->required_count, $filled)
            ? '締切（満員）'
            : '募集中（あと'.self::remaining($project->required_count, $filled).'名）';
    }
}
