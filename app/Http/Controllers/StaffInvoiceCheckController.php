<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Models\StaffInvoiceCheck;
use App\Support\AssignmentRole;
use App\Support\OfficeScope;
use App\Support\ProjectContentName;
use App\Support\StaffInvoiceDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * スタッフ別アサイン（請求書チェック）（/staff-invoice-check）。2026-10-13 baba要望。
 *
 * 【なぜ作ったか】スタッフから請求書をもらったら、アサイン表の人数の隣に四角を書いて
 *   「請求書を出したよ」「チェックしたよ」を手で埋めていた。アサイン表は案件ごとの並びなので、
 *   1人ぶんを請求書と突き合わせるのが大変（名前が見つからない人もいる）。
 *   → 月を選ぶと、スタッフごとに「その月のアサイン（日付・案件・役割・状態）」が並び、
 *     📨請求書・✅チェック済の印を押せる画面にした。印は押した瞬間に保存（押し忘れ事故を防ぐ）。
 *
 * 【出すもの】その月にアサイン（キャンセル以外）がある**スタッフ**（社員は請求書を出さないので出さない）。
 *   ⚠ 名簿と名前が合わずに取込で飛ばされた人は、アサインが無いのでここにも出ない
 *     （アサイン表の取込の画面の「名簿に無い名前」を見る）。
 * 【見る・押す】社員以上（ルートのグループが tier:employee）。拠点で絞る＝OfficeScope。
 */
class StaffInvoiceCheckController extends Controller
{
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('ym'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('ym').'-01')->startOfDay()
            : Carbon::today()->startOfMonth();
        $ym = $month->format('Y-m');

        $staffQuery = OfficeScope::applyToPeople(Person::staff(), $office);
        $staffIds = $staffQuery->pluck('id')->all();

        $rows = Assignment::whereIn('staff_id', $staffIds)
            ->whereDate('date', '>=', $month->format('Y-m-d'))
            ->whereDate('date', '<=', $month->copy()->endOfMonth()->format('Y-m-d'))
            ->where('status', '!=', 'キャンセル')
            ->orderBy('date')->get(['project_id', 'staff_id', 'role', 'status', 'date']);

        $projects = Project::whereIn('id', $rows->pluck('project_id')->unique())->get()->keyBy('id');
        $contentNames = Content::pluck('content_name', 'id');
        $people = Person::whereIn('id', $rows->pluck('staff_id')->unique())->get(['id', 'name', 'name_kana', 'office', 'nearest_station'])->keyBy('id');
        $checks = StaffInvoiceCheck::where('ym', $ym)->get()->keyBy('staff_id');

        $list = [];
        foreach ($rows->groupBy('staff_id') as $sid => $mine) {
            $p = $people->get($sid);
            if (! $p) {
                continue;
            }
            $cases = [];
            foreach ($mine as $a) {
                $pr = $projects->get($a->project_id);
                $d = Carbon::parse($a->date);
                $cases[] = [
                    'ymd' => $d->format('Y-m-d'),
                    'roleCode' => (string) $a->role,
                    'lodging' => $pr ? (string) ($pr->lodging ?? '') : '',
                    'date' => $d->format('n/j').'('.self::WEEKDAYS[$d->dayOfWeek].')',
                    'projectId' => $a->project_id,
                    'name' => $pr ? ProjectContentName::of($pr, $contentNames, (string) $pr->project_name) : $a->project_id,
                    'client' => $pr ? (string) ($pr->client ?? '') : '',
                    'dayType' => $pr ? (string) ($pr->date_type ?? '本番') : '',
                    'role' => AssignmentRole::label((string) $a->role),
                    // 請求書と見比べる材料（2026-10-13 baba「住所と集合解散・リアル/リアルロング/オンラインも」）。
                    'format' => $pr ? trim((string) ($pr->format ?? '')) : '',
                    'meet' => $pr ? trim((string) ($pr->start_time ?? '')) : '',
                    'leave' => $pr ? trim((string) ($pr->end_time ?? '')) : '',
                    'place' => $pr ? trim((string) preg_replace('/\s+/u', ' ', (string) ($pr->location ?? ''))) : '',
                    'status' => (string) $a->status,
                    // 中止になった案件も消さずに印を付けて出す（キャンセル料の請求が来ることがあるため）。
                    'cancelled' => $pr ? (bool) $pr->is_cancelled : false,
                ];
            }
            if (! $cases) {
                continue;
            }
            $c = $checks->get($sid);
            $list[] = [
                'id' => $sid,
                'name' => (string) $p->name,
                'kana' => (string) ($p->name_kana ?? ''),
                'office' => (string) ($p->office ?? ''),
                'cases' => $cases,
                'confirmed' => count(array_filter($cases, fn ($x) => $x['status'] === '確定')),
                'received' => $c?->received_at ? ['at' => $c->received_at->format('n/j H:i'), 'by' => (string) $c->received_by] : null,
                'checked' => $c?->checked_at ? ['at' => $c->checked_at->format('n/j H:i'), 'by' => (string) $c->checked_by] : null,
                'copyText' => self::copyText((string) $p->name, $month, $cases),
                // 請求書に出てくるはずの品目（マニュアルの書き方・標準単価の目安）。正本＝StaffInvoiceDraft。
                'draft' => StaffInvoiceDraft::build($cases, (string) ($p->nearest_station ?? '')),
            ];
        }
        // 並びは読み（name_kana）順＝請求書の束をあいうえお順で見やすく。読みが無ければ名前。
        usort($list, fn ($a, $b) => strcmp($a['kana'] ?: $a['name'], $b['kana'] ?: $b['name']));

        // 前の月・次の月（拠点の選択は持ち回る）。
        $officeQ = $request->filled('office') ? '&office='.urlencode((string) $request->query('office')) : '';

        return view('staff_invoice_check', [
            'month' => $month,
            'prevUrl' => '/staff-invoice-check?ym='.$month->copy()->subMonth()->format('Y-m').$officeQ,
            'nextUrl' => '/staff-invoice-check?ym='.$month->copy()->addMonth()->format('Y-m').$officeQ,
            'ym' => $ym,
            'office' => $office,
            'list' => $list,
            'counts' => [
                'all' => count($list),
                'received' => count(array_filter($list, fn ($r) => $r['received'])),
                'checked' => count(array_filter($list, fn ($r) => $r['checked'])),
            ],
        ]);
    }

    /** 📨／✅ の印を付ける・外す（押した瞬間に保存）。 */
    public function mark(Request $request)
    {
        $data = $request->validate([
            'staff_id' => ['required', 'string', 'exists:people,id'],
            'ym' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'field' => ['required', 'in:received,checked'],
            'on' => ['required', 'boolean'],
        ]);
        $me = Auth::user();
        $row = StaffInvoiceCheck::firstOrNew(['staff_id' => $data['staff_id'], 'ym' => $data['ym']]);
        $on = (bool) $data['on'];
        $row->{$data['field'].'_at'} = $on ? now() : null;
        $row->{$data['field'].'_by'} = $on ? (string) ($me->name ?? '') : null;
        $row->save();

        return response()->json([
            'ok' => true,
            'at' => $on ? $row->{$data['field'].'_at'}->format('n/j H:i') : '',
            'by' => $on ? (string) $row->{$data['field'].'_by'} : '',
        ]);
    }

    /** 1人ぶんの明細をコピーする文（請求書と見比べる・チャットに貼る用）。表や罫線は使わない。 */
    private static function copyText(string $name, Carbon $month, array $cases): string
    {
        $lines = [$name.'さん '.$month->format('n月').'のアサイン '.count($cases).'件'];
        foreach ($cases as $c) {
            $lines[] = $c['date'].' '.$c['name'].($c['client'] !== '' ? '／'.$c['client'] : '')
                .($c['dayType'] !== '' && $c['dayType'] !== '本番' ? '［'.$c['dayType'].'］' : '')
                .'　'.$c['role'].($c['status'] !== '確定' ? '（'.$c['status'].'）' : '')
                .($c['cancelled'] ? '【案件中止】' : '')
                .self::detailLine($c);
        }

        return implode("\n", $lines);
    }

    /** 2行目＝「　リアルロング／集合 7:00・解散 18:00／会場住所」（無い項目は書かない）。 */
    public static function detailLine(array $c): string
    {
        $time = trim(($c['meet'] !== '' ? '集合 '.$c['meet'] : '').($c['meet'] !== '' && $c['leave'] !== '' ? '・' : '').($c['leave'] !== '' ? '解散 '.$c['leave'] : ''));
        $bits = array_filter([$c['format'], $time, $c['place']], fn ($v) => $v !== '');

        return $bits ? "\n　".implode('／', $bits) : '';
    }
}
