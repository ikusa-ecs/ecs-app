<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\OfficeScope;
use App\Support\PersonMonthCases;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 社員別アサイン一覧（/employee-assignments）。2026-10-13 baba要望。
 *
 * 【なぜ作ったか】社員が自分のアサインを見られるのはマイページだけで、ほかの社員のぶんを
 *   一覧で見る場所が無かった。→ 月を選ぶと、社員ごとに「その月のアサイン」が並ぶ画面。
 *   形は「請求書チェック・手当管理表」（スタッフ）と同じ。ただし請求書・手当は社員に関係ないので付けない。
 * 1人ぶんの明細の正本＝App\Support\PersonMonthCases（スタッフの画面と同じもの）。
 * 【見る】社員以上（ルートのグループが tier:employee）。見るだけ。拠点で絞る＝OfficeScope。
 */
class EmployeeAssignmentsController extends Controller
{
    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('ym'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('ym').'-01')->startOfDay()
            : Carbon::today()->startOfMonth();

        // 社歴順（社員名簿と同じ並び）。
        // ⚠ 退職した人も読む（2026-10-16 baba決定）＝**その月にアサインがあれば出す**。
        //   以前は在籍中だけを読んでいたので、退職すると**過去の月からも消えて**いた。
        //   アサインが無い月は出さない（「アサインなし」の欄にも並べない）。
        $employees = OfficeScope::applyToPeople(Person::employees(), $office)
            ->bySeniority()->get(['id', 'name', 'office', 'department', 'hire_date', 'active']);
        $built = PersonMonthCases::build($employees->pluck('id')->all(), $month);

        $list = [];
        $none = [];
        foreach ($employees as $p) {
            $cases = $built['byPerson'][$p->id] ?? [];
            if (! $cases) {
                if ($p->active === false) {
                    continue;   // 退職してその月に仕事が無い人は出さない
                }
                $none[] = (string) $p->name;

                continue;
            }
            $roles = array_count_values(array_column($cases, 'roleCode'));
            $list[] = [
                'id' => $p->id,
                'name' => (string) $p->name,
                'office' => (string) ($p->office ?? ''),
                'dept' => (string) ($p->department ?? ''),
                'cases' => $cases,
                'd' => $roles['D'] ?? 0,
                'sd' => $roles['SD'] ?? 0,
                'tentative' => count(array_filter($cases, fn ($c) => $c['status'] === '仮')),
                'copyText' => self::copyText((string) $p->name, $month, $cases),
            ];
        }

        $officeQ = $request->filled('office') ? '&office='.urlencode((string) $request->query('office')) : '';

        return view('employee_assignments', [
            'month' => $month,
            'prevUrl' => '/employee-assignments?ym='.$month->copy()->subMonth()->format('Y-m').$officeQ,
            'nextUrl' => '/employee-assignments?ym='.$month->copy()->addMonth()->format('Y-m').$officeQ,
            'list' => $list,
            'none' => $none,
            'total' => array_sum(array_map(fn ($r) => count($r['cases']), $list)),
        ]);
    }

    /** 1人ぶんの明細をコピーする文（チャットに貼る用）。表や罫線は使わない。 */
    private static function copyText(string $name, Carbon $month, array $cases): string
    {
        $lines = [$name.'さん '.$month->format('n月').'のアサイン '.count($cases).'件'];
        foreach ($cases as $c) {
            $lines[] = $c['date'].' '.$c['name'].($c['client'] !== '' ? '／'.$c['client'] : '')
                .($c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '')
                .'　'.$c['role'].($c['status'] !== '確定' ? '（'.$c['status'].'）' : '')
                .($c['cancelled'] ? '【案件中止】' : '')
                .StaffInvoiceCheckController::detailLine($c);
        }

        return implode("\n", $lines);
    }
}
