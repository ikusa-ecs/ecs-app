<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\AssignmentRole;
use App\Support\ExperienceCount;
use App\Support\OfficeScope;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 経験回数（/experience）。2026-08-28 baba要望。
 *
 * 【なぜ独立した画面にしたか】
 * それまでは名簿の「詳細」を1人ずつ開かないと経験回数が見られなかった。
 * アサインで本当に知りたいのは「**このコンテンツをやったことがある人は誰か**」
 * 「**このポジションの経験が多いのは誰か**」で、1人ずつ開いては探せない。
 * そこで一覧で見比べられる画面を分けた。⚠ 拠点で絞れる（2026-08-28 baba要望）。
 *
 * 【数え方】
 * ⚠ ここには数え方を書かない。正本は App\Support\ExperienceCount。
 *   （確定のアサインで開催日が過ぎたもの・キャンセルは数えない）
 *   ⚠ 表に保存していない＝開くたびに数え直す。アサインを直せばここも自動で直る。
 */
class ExperienceController extends Controller
{
    public function index()
    {
        [$people, $experience] = $this->collect();

        return view('experience', [
            'people' => $people,
            'experience' => $experience,
            // このデータの中に出てくるコンテンツ・ポジションだけを選択肢にする
            // ＝誰もやっていないコンテンツを選んで「0件」を見せない。
            'contentOptions' => $this->contentsIn($experience),
            'roleOptions' => $this->rolesIn($experience),
            // 拠点で絞るための選択肢。既定は自分の拠点。
            // ⚠ 拠点名は画面に書かない。正本は拠点マスタ（共通設定 → マスタ管理）。
            'offices' => OfficeScope::options(),
            'myOffice' => OfficeScope::filterSingle(request()),
        ]);
    }

    /**
     * CSVで落とす。1行＝「人 × コンテンツ」。
     * ?type=role にするとポジション別（1行＝「人 × ポジション」）。
     *
     * ⚠ 画面と同じ数え方にするため、数えるのは必ず ExperienceCount を通す。
     */
    public function exportCsv(): StreamedResponse
    {
        $byRole = request()->query('type') === 'role';
        [$people, $experience] = $this->collect();

        $head = $byRole
            ? ['拠点', '区分', '番号', '氏名', 'ポジション', '回数', '最後にやった日']
            : ['拠点', '区分', '番号', '氏名', 'コンテンツ', '回数', 'そのコンテンツでのポジション', '最後にやった日'];

        $name = 'ecs_experience_'.($byRole ? 'role' : 'content').'.csv';

        return response()->streamDownload(function () use ($people, $experience, $byRole, $head) {
            $out = fopen('php://output', 'w');
            // ⚠ Excelで開いたときに文字化けしないよう BOM を付ける（他のCSV出力と同じ）。
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head);

            foreach ($people as $p) {
                $e = $experience[$p['id']] ?? null;
                if (! $e || ! $e['projects']) {
                    continue; // 経験ゼロの人は行を作らない（読みにくくなるため）
                }
                $base = [$p['office'], $p['roleLabel'], $p['id'], $p['name']];

                if ($byRole) {
                    foreach ($e['byRole'] as $r) {
                        fputcsv($out, array_merge($base, [$r['label'], $r['count'], $r['last'] ?? '']));
                    }

                    continue;
                }

                foreach ($e['byContent'] as $c) {
                    $cr = $e['byContentRole'][$c['name']] ?? [];
                    $roles = [];
                    foreach ($cr as $code => $n) {
                        $roles[] = $code.' '.$n;
                    }
                    fputcsv($out, array_merge($base, [
                        $c['name'], $c['count'], implode(' / ', $roles), $c['last'] ?? '',
                    ]));
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 画面とCSVで共通の材料。
     * ⚠ 経験回数は必ず forMany でまとめて数える（1人ずつ引くと人数ぶんSQLが走る）。
     *
     * @return array{0: Collection, 1: array}
     */
    private function collect(): array
    {
        // 社員もスタッフも同じ土俵で見る（同じ現場に出るため）。
        // 退職・停止の人も残す＝過去に誰がやったかを追えるように。画面側で切り替えられる。
        $people = Person::query()
            ->orderBy('name')
            ->get(['id', 'name', 'name_kana', 'role', 'office', 'active'])
            ->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'kana' => $p->name_kana ?? '',
                'role' => $p->role,
                'roleLabel' => $p->role === 'employee' ? '社員' : 'スタッフ',
                // ⚠ 拠点が空の人は「東京」として扱う（名簿・案件と同じ決まり）。
                'office' => trim((string) $p->office) !== '' ? $p->office : OfficeScope::DEFAULT_OFFICE,
                'active' => (bool) $p->active,
            ])
            ->values();

        return [$people, ExperienceCount::forMany($people->pluck('id')->all())];
    }

    /**
     * 集計に出てくるコンテンツ名（多い順 → 名前順）。
     *
     * @return list<string>
     */
    private function contentsIn(array $experience): array
    {
        $count = [];
        foreach ($experience as $e) {
            foreach ($e['byContent'] as $c) {
                $count[$c['name']] = ($count[$c['name']] ?? 0) + $c['count'];
            }
        }
        // 多い順。同数なら名前順（毎回同じ並びになるように）。
        uksort($count, fn ($a, $b) => [$count[$b], $a] <=> [$count[$a], $b]);

        return array_keys($count);
    }

    /**
     * 集計に出てくるポジション（決まった並び D→SD→OP→… で返す）。
     *
     * @return list<array{code:string, label:string}>
     */
    private function rolesIn(array $experience): array
    {
        $used = [];
        foreach ($experience as $e) {
            foreach ($e['byRole'] as $r) {
                $used[$r['role']] = true;
            }
        }

        $out = [];
        foreach (AssignmentRole::LABELS as $code => $label) {
            if (isset($used[$code])) {
                $out[] = ['code' => $code, 'label' => $label];
            }
        }

        return $out;
    }
}
