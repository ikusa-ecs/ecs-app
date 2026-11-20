<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Support\EventCount;
use App\Support\OfficeScope;
use App\Support\PersonMonthCases;
use App\Support\SameVenueKey;
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
            // 数えるのは中止になっていない案件だけ（一覧には【案件中止】で出す・2026-10-21 baba）。
            $live = array_values(array_filter($cases, fn ($c) => empty($c['cancelled'])));
            // D・SD の件数は本番日だけ数える（2026-11-16 小沼さん決定：本番日のDがそのイベント全部の日のDなので、
            // 前日設営・リハ日・予備日まで数えると件数がふくらむ）。一覧の行は今までどおり全部出す。
            $mainLive = array_filter($live, fn ($c) => EventCount::isMainDay($c['dayType'] ?? null));
            // 同じ日・同じ会場・同じお客様のかけもちは1件（2026-11-20 小沼さん決定・正本 SameVenueKey）。
            // D・SD は役割ごとにまとめる（同じ会場でDとSDを別の案件で持つ、は別の仕事なので両方数える）。
            $sameKeyOf = fn ($c) => $c['sameKey'] ?? null;
            $roles = [];
            foreach (['D', 'SD'] as $rc) {
                $roles[$rc] = count(SameVenueKey::unique(
                    array_values(array_filter($mainLive, fn ($c) => $c['roleCode'] === $rc)), $sameKeyOf));
            }
            $mainCount = count(SameVenueKey::unique(array_values($mainLive), $sameKeyOf));
            $list[] = [
                'id' => $p->id,
                'name' => (string) $p->name,
                'office' => (string) ($p->office ?? ''),
                'dept' => (string) ($p->department ?? ''),
                'cases' => $cases,
                'd' => $roles['D'] ?? 0,
                'sd' => $roles['SD'] ?? 0,
                // 件数（明細○件・のべ○件）も本番日だけ（2026-11-20 小沼さん決定：アサイン表と同じく
                // 当日運営だけ数える）。設営・リハ日は otherDays に分けて「ほかに設営・リハ○件」と出す。
                'tentative' => count(array_filter($mainLive, fn ($c) => $c['status'] === '仮')),
                'liveCount' => $mainCount,
                'otherDays' => count($live) - count($mainLive),
                // 同じ会場のかけもちで件数から外した数（明細には出したまま「かけもち ○」と出す）。
                'sameVenue' => count($mainLive) - $mainCount,
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
            'total' => array_sum(array_column($list, 'liveCount')),
        ]);
    }

    /** 1人ぶんの明細をコピーする文（チャットに貼る用）。表や罫線は使わない。 */
    private static function copyText(string $name, Carbon $month, array $cases): string
    {
        // 件数には中止になった案件を入れない（一覧には【案件中止】で出す・2026-10-21 baba）。
        // 件数は本番日だけ（2026-11-20 小沼さん決定）。設営・リハ日は「ほかに」に分けて書く。
        $stopped = count(array_filter($cases, fn ($c) => ! empty($c['cancelled'])));
        $live = array_filter($cases, fn ($c) => empty($c['cancelled']));
        $mainRows = array_values(array_filter($live, fn ($c) => EventCount::isMainDay($c['dayType'] ?? null)));
        $other = count($live) - count($mainRows);
        // 同じ日・同じ会場・同じお客様のかけもちは1件（正本 SameVenueKey）。
        $main = count(SameVenueKey::unique($mainRows, fn ($c) => $c['sameKey'] ?? null));
        $same = count($mainRows) - $main;
        $extra = array_filter([$other ? '設営・リハ '.$other.'件' : '', $same ? '同じ会場のかけもち '.$same.'件' : '', $stopped ? '中止 '.$stopped.'件' : '']);
        $lines = [$name.'さん '.$month->format('n月').'のアサイン '.$main.'件'
            .($extra ? '（ほかに'.implode('・', $extra).'）' : '')];
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
