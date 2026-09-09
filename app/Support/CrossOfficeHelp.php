<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectShare;
use Illuminate\Support\Facades\Auth;

/**
 * 他拠点の人を自拠点の案件に入れたら、自動で「ヘルプ」として記録する（2026-09-09 baba要望）。
 *
 * 【babaの言葉】「日別ボードで他拠点の社員さんを自拠点にアサインするときはヘルプ扱いに自動でしてほしい」
 *
 * 【なぜ要るか】
 * 全拠点運用では、案件は**登録した拠点のもの**のまま動かさない（複製しない）。
 * 他拠点の人に手伝ってもらったことは `project_shares` に「ヘルプ」として残す決まり
 * （設計書19.2・[[ecs-multi-office-design]]）。ところが、この記録は
 * **アサイン表の取込と、案件一覧の「自拠点にコピー」からしか付かなかった**。
 * 日別ボードで他拠点の人を入れても記録が残らず、
 * 「誰にどれだけ手伝ってもらったか」が拠点別の集計から抜けていた。
 *
 * ⚠ **すでに記録がある場合は上書きしない。** 「巻き取り」（案件ごと引き受けた）は
 *   ヘルプより強い関係なので、あとからヘルプで塗りつぶすと関係が弱く書き換わってしまう。
 * ⚠ 拠点が空の人・空の案件は何もしない（勘で拠点を決めない）。
 * ⚠ アサインを外しても記録は消さない。「手伝ってもらった事実」は残す
 *   （消すと、その月の拠点間のやり取りが後から追えなくなる）。消したいときは人が消す。
 */
final class CrossOfficeHelp
{
    /** 記録する種類（巻き取りは人が選ぶもの。自動で付けるのはヘルプだけ）。 */
    public const KIND = 'ヘルプ';

    /**
     * 必要ならヘルプとして記録する。
     *
     * @return bool 新しく記録したら true（すでにある・対象外なら false）
     */
    public static function record(Project $project, ?Person $person): bool
    {
        if (! $person) {
            return false;
        }

        $projectOffice = trim((string) $project->office);
        $personOffice = trim((string) $person->office);

        // どちらかが空＝勘で決めない。同じ拠点＝ヘルプではない。
        if ($projectOffice === '' || $personOffice === '' || $projectOffice === $personOffice) {
            return false;
        }

        // ⚠ すでに「巻き取り」等が入っていたら、そのまま残す（firstOrCreate＝上書きしない）。
        $share = ProjectShare::firstOrCreate(
            ['project_id' => $project->id, 'office' => $personOffice],
            ['kind' => self::KIND, 'created_by' => Auth::id()]
        );

        return $share->wasRecentlyCreated;
    }
}
