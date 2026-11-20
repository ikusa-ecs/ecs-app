<?php

namespace App\Support;

use App\Models\Person;
use App\Models\Project;

/**
 * 「ある拠点の案件を、いつでもほかの拠点のスタッフにも見せる」組の正本（2026-11-20 小沼さん決定）。
 *
 * 東北拠点の案件は、東京のスタッフにも手伝ってもらう運用。
 * これまでは公開ボードで東京へ共有（ヘルプ）＋東京で公開、を毎回しないと東京のスタッフに出なかった。
 * → **東北の案件は、東北で公開している（＝東北のスタッフに見えている）あいだ、東京のスタッフにも出す**。
 *
 * 決まり：
 *  ・公開前・非公開の案件は出さない（見えるかどうかは東北の公開＝OfficePublish::scopePublishedFor('東北') で決める）。
 *  ・逆向き（東京の案件を東北のスタッフに）や、ほかの拠点（札幌・名古屋など）には広げない。
 *  ・組を変えたい・増やしたいときは、下の REACH の1か所だけ直す（画面やコントローラーに「東北」を書かない）。
 *
 * ⚠ スタッフの一覧（募集中タブ）・まとめてエントリーの門・最近の変更・カレンダーの件数・
 *   社員側のエントリー一覧（東北の担当が東京のスタッフの応募を見る）は、すべてここを通す。
 *   一覧の条件と保存の門が別々の式になると「押せたのに戻る」事故になる（2026-09-18 公開ボード）。
 */
final class StaffOfficeReach
{
    /**
     * 案件の拠点 => その拠点で公開中なら、いっしょに見せるスタッフの拠点。
     *
     * @var array<string, list<string>>
     */
    public const REACH = [
        '東北' => ['東京'],
    ];

    /**
     * そのスタッフの拠点が、自拠点のほかに見られる「案件の拠点」の一覧（例：東京 => ['東北']）。
     *
     * @return list<string>
     */
    public static function sourcesFor(?string $staffOffice): array
    {
        $staffOffice = trim((string) $staffOffice);
        if ($staffOffice === '') {
            return [];
        }
        $out = [];
        foreach (self::REACH as $source => $viewers) {
            // 自分の拠点の案件は、もともとの決まり（OfficePublish）で見えるので足さない。
            if ($source !== $staffOffice && in_array($staffOffice, $viewers, true)) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * 案件の問い合わせを「その拠点のスタッフに見せてよい案件」に絞る（募集中タブ・最近の変更・まとめてエントリーの門）。
     *
     *  ①これまでどおり：自分の拠点で公開中（OfficePublish）かつ 自拠点の案件か自拠点に共有された案件（OfficeScope）
     *  ②追加：REACH で見られる拠点の**その拠点で登録された**案件で、その拠点で公開中のもの
     *
     * ⚠ ②は「登録した拠点が東北」の案件だけ。東北に共有されてきた他拠点の案件は東京に広げない。
     */
    public static function scopeVisibleToStaff($query, string $office)
    {
        $sources = self::sourcesFor($office);

        return $query->where(function ($w) use ($office, $sources) {
            $w->where(fn ($own) => OfficeScope::applyToProjects(OfficePublish::scopePublishedFor($own, $office), $office));
            foreach ($sources as $src) {
                $w->orWhere(fn ($ext) => OfficePublish::scopePublishedFor(self::ownedBy($ext, $src), $src));
            }
        });
    }

    /**
     * 稼働希望カレンダーの件数に足す案件（そのスタッフに見えている、ほかの拠点の案件）。
     * 自拠点の件数は今までどおり（未公開も数える）。追加の拠点の分は**東北で公開中のものだけ**
     * （東京のスタッフの画面に出ていない東北の案件まで数えると、出てこない案件の件数が増えて紛らわしい）。
     */
    public static function scopeExtraForStaff($query, string $office)
    {
        $sources = self::sourcesFor($office);
        if ($sources === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($w) use ($sources) {
            foreach ($sources as $src) {
                $w->orWhere(fn ($ext) => OfficePublish::scopePublishedFor(self::ownedBy($ext, $src), $src));
            }
        });
    }

    /** その案件は、そのスタッフの拠点にとって「よその拠点から広げて見せている」案件か（締切の拠点を選ぶのに使う）。 */
    public static function isReachedProject(Project $p, ?string $staffOffice): bool
    {
        return in_array(self::owner($p), self::sourcesFor($staffOffice), true);
    }

    /**
     * 社員側：その応募者を「いま見ている拠点」の一覧（エントリー一覧・新着・日別ボードの希望者・ピックアップ・案件別アサイン）に出すか。
     *
     *  ・これまでどおり：見ている拠点の人（OfficeScope::personIn）。全拠点表示なら全員。
     *  ・追加：見ている拠点＝案件を登録した拠点（例 東北）で、その人が REACH で見せている拠点（例 東京）の人。
     *    東北の担当が、東北の案件にエントリーした東京のスタッフを候補にできるように。
     *
     * ⚠ 東北に共有されてきた他拠点の案件や、東北の案件を名古屋で見ているときは広げない
     *   （2026-10-07「他拠点に頼むとエントリーに他拠点のスタッフが混ざる」の決まりはそのまま）。
     */
    public static function applicantShown(?Person $person, ?string $viewOffice, Project $p): bool
    {
        if (OfficeScope::personIn($person, $viewOffice)) {
            return true;
        }
        if (! $person) {
            return false;
        }
        $owner = self::owner($p);
        if ($owner !== trim((string) $viewOffice)) {
            return false;
        }
        $personOffice = trim((string) ($person->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;

        return in_array($personOffice, self::REACH[$owner] ?? [], true);
    }

    /** 案件を登録した拠点（空は東京あつかい＝OfficeScope と同じ）。 */
    private static function owner(Project $p): string
    {
        return trim((string) ($p->office ?? '')) ?: OfficeScope::DEFAULT_OFFICE;
    }

    /** 「その拠点で登録された案件」に絞る（空は東京あつかい）。 */
    private static function ownedBy($query, string $office)
    {
        return $query->where(function ($q) use ($office) {
            $q->where('office', $office);
            if ($office === OfficeScope::DEFAULT_OFFICE) {
                $q->orWhereNull('office')->orWhere('office', '');
            }
        });
    }
}
