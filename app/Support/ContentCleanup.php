<?php

namespace App\Support;

use App\Models\Content;
use App\Models\ContentPaperStock;
use App\Models\ContentRoleRequirement;
use App\Models\Project;
use App\Models\StaffContentExperience;
use Illuminate\Support\Facades\DB;

/**
 * 取込で増えてしまったコンテンツの片づけ（2026-09-29 baba「コンテンツは量産しないでほしい」）。
 *
 * 見つけるもの（2種類）：
 *   ・まとめ … 「謎パ・格付けバトル」のように、台帳にある複数のコンテンツをつないだ名前
 *              → その案件を「謎パ」「格付けバトル」の2つにつなぎ直して、まとめの方を消す
 *   ・同じ   … 書き方だけ違う（全角半角・空白・「・」）同じ名前が、前からある
 *              → 前からあるほう（IDが小さいほう）につなぎ直して、あとからできたほうを消す
 *
 * ⚠ 候補を出すだけ。つなぎ直して消すのは、画面で人が選んで押したものだけ。
 * ⚠ 押したときは候補を**もう一度計算し直して**、まだ候補のものだけ直す（画面を開いたあとに台帳が変わっていても安全）。
 * ⚠ 紙の在庫が入っているコンテンツは候補にしない（在庫の数をどこに寄せるか決められないため）。
 */
final class ContentCleanup
{
    private const SEPARATORS = '/[・･＋+／\/、,，＆&×\r\n]+/u';

    /**
     * @return list<array{id:string, name:string, kind:string, targets:list<array{id:string,name:string}>, projects:int, hasReq:bool}>
     */
    public static function candidates(): array
    {
        $contents = Content::orderBy('id')->get(['id', 'content_name']);
        $stocked = ContentPaperStock::pluck('content_id')->unique()->flip();
        $withReq = ContentRoleRequirement::pluck('content_id')->unique()->flip();

        // 整えた名前 → いちばん古い1件
        $byNorm = [];
        foreach ($contents as $c) {
            $n = RoleRequirementCsv::normalizeName((string) $c->content_name);
            if ($n !== '' && ! isset($byNorm[$n])) {
                $byNorm[$n] = ['id' => (string) $c->id, 'name' => (string) $c->content_name];
            }
        }

        $usage = self::usage();
        $out = [];
        foreach ($contents as $c) {
            $id = (string) $c->id;
            $name = (string) $c->content_name;
            if (isset($stocked[$id])) {
                continue;
            }
            $norm = RoleRequirementCsv::normalizeName($name);

            // 同じ（書き方だけ違う）で、前からあるほうが別にある
            $older = $byNorm[$norm] ?? null;
            if ($older !== null && $older['id'] !== $id) {
                $out[] = ['id' => $id, 'name' => $name, 'kind' => '同じ', 'targets' => [$older],
                    'projects' => $usage[$id] ?? 0, 'hasReq' => isset($withReq[$id])];

                continue;
            }

            // まとめ（区切ると2つ以上に分かれ、どれも自分以外の台帳にある）
            $parts = array_values(array_unique(array_filter(
                array_map('trim', preg_split(self::SEPARATORS, $name) ?: []),
                fn ($s) => $s !== ''
            )));
            if (count($parts) < 2) {
                continue;
            }
            $targets = [];
            foreach ($parts as $p) {
                $hit = $byNorm[RoleRequirementCsv::normalizeName($p)] ?? null;
                if ($hit === null || $hit['id'] === $id) {
                    $targets = [];
                    break;
                }
                $targets[$hit['id']] = $hit;
            }
            if (count($targets) >= 2) {
                $out[] = ['id' => $id, 'name' => $name, 'kind' => 'まとめ', 'targets' => array_values($targets),
                    'projects' => $usage[$id] ?? 0, 'hasReq' => isset($withReq[$id])];
            }
        }

        return $out;
    }

    /**
     * 選んだ候補をつなぎ直して消す。
     *
     * @param  list<string>  $ids
     * @return array{done: list<string>, projects: int}
     */
    public static function apply(array $ids): array
    {
        $byId = collect(self::candidates())->keyBy('id');
        $done = [];
        $touched = 0;

        DB::transaction(function () use ($ids, $byId, &$done, &$touched) {
            foreach ($ids as $id) {
                $cand = $byId->get($id);
                if ($cand === null) {
                    continue;   // もう候補ではない（画面を開いたあとに変わった）→ 何もしない
                }
                $targetIds = array_column($cand['targets'], 'id');
                $targetNames = array_column($cand['targets'], 'name');

                foreach (Project::whereNotNull('content_ids')->get() as $p) {
                    $cids = array_map('strval', (array) ($p->content_ids ?? []));
                    if (! in_array($id, $cids, true)) {
                        continue;
                    }
                    $newIds = [];
                    foreach ($cids as $x) {
                        foreach ($x === $id ? $targetIds : [$x] as $y) {
                            if (! in_array($y, $newIds, true)) {
                                $newIds[] = $y;
                            }
                        }
                    }
                    $names = array_map('strval', (array) ($p->content_names ?? []));
                    $newNames = [];
                    foreach ($names === [] ? [$cand['name']] : $names as $n) {
                        foreach ($n === $cand['name'] ? $targetNames : [$n] as $m) {
                            if (! in_array($m, $newNames, true)) {
                                $newNames[] = $m;
                            }
                        }
                    }
                    $p->content_ids = $newIds;
                    $p->content_names = $newNames;
                    if ((string) $p->project_name === $cand['name']) {
                        $p->project_name = implode('・', $newNames);
                    }
                    $p->save();   // ⚠ 保存イベント経由で編集履歴にも残る
                    $touched++;
                }

                // 経験の記録は先頭のつなぎ先へ寄せる（同じ人×同じ先がもうあれば、古いほうは消す）。
                foreach (StaffContentExperience::where('content_id', $id)->get() as $e) {
                    $dup = StaffContentExperience::where('staff_id', $e->staff_id)
                        ->where('content_id', $targetIds[0])->exists();
                    $dup ? $e->delete() : $e->update(['content_id' => $targetIds[0]]);
                }

                ContentRoleRequirement::where('content_id', $id)->delete();
                Content::where('id', $id)->delete();
                $done[] = $cand['name'];
            }
        });

        return ['done' => $done, 'projects' => $touched];
    }

    /** コンテンツID → 使っている案件の数。 */
    private static function usage(): array
    {
        $n = [];
        foreach (Project::whereNotNull('content_ids')->pluck('content_ids') as $ids) {
            foreach (array_unique(array_map('strval', (array) $ids)) as $id) {
                $n[$id] = ($n[$id] ?? 0) + 1;
            }
        }

        return $n;
    }
}
