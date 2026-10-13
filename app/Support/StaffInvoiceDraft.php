<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * スタッフの月のアサインから「請求書に出てくるはずの品目」を作る（2026-10-13 baba「請求書の作り方を見て要りそうな情報を載せて」）。
 *
 * 元＝ドライブ「【マニュアル】請求書作成（26年5月～）」（野口さん・INVOYへの入力方法）。書き方・単価はここに合わせる。
 *   基本報酬：オンライン 6,000／リアル 9,000（1日2案件＝×1.5・3案件＝×2）
 *   手当：MC・スライド 1,000/案件、ロング・宿泊（前泊）・運転・事前レクチャー/リハーサル 2,000/回、前日設営 4,000/回
 *   交通費：往復3,000円まで（税区分は対象外）
 * ⚠ 金額は**標準単価での目安**。本当は人ごとに日当も交通費も違う（2026-08-17 baba）＝「合っているか」の判定には使わない。
 * ⚠ 運転・スライド・事前レクチャーは、社員が「手当の記録」（staff_allowances・単価の正本＝StaffAllowances）に入れたぶんを足す。
 *   交通費・キャンセル料はECSでは分からない＝「要確認」として出すだけ。
 * ⚠ 単価をここ以外に書かない（マニュアルが変わったらここ1か所を直す）。
 */
final class StaffInvoiceDraft
{
    public const BASE = ['オンライン' => 6000, 'リアル' => 9000];

    public const MC = 1000;

    public const LONG = 2000;

    public const STAY = 2000;

    public const REHEARSAL = 2000;

    public const PREP = 4000;

    public const TRAVEL_CAP = 3000;

    /**
     * @param  list<array{ymd:string, name:string, format:string, roleCode:string, dayType:string, lodging:string, cancelled:bool, status:string}>  $cases
     * @return array{items: list<array{label:string, qty:string, price:int, amount:int}>, total:int, checks: list<string>}
     */
    public static function build(array $cases, string $station = '', array $allowances = []): array
    {
        $items = [];
        $checks = [];
        $md = fn (string $ymd) => Carbon::parse($ymd)->format('n/j');
        // 「10/5、12、20」の書き方（マニュアルの例どおり＝同じ月は日だけ続ける）。
        $days = function (array $ymds) {
            sort($ymds);
            $out = [];
            $lastMonth = null;
            foreach ($ymds as $y) {
                $d = Carbon::parse($y);
                $out[] = $lastMonth === $d->month ? (string) $d->day : $d->format('n/j');
                $lastMonth = $d->month;
            }

            return implode('、', $out);
        };

        $live = array_values(array_filter($cases, fn ($c) => ! $c['cancelled']));
        $main = array_values(array_filter($live, fn ($c) => in_array($c['dayType'], ['', '本番'], true)));

        // ① 基本報酬（本番の日だけ）。同じ日・同じ種類の案件数で 1／2／3案件/日 に分ける。
        $byKindDay = [];
        foreach ($main as $c) {
            // ⚠ リアル／リアルロング／オンラインの見分けは正本 ProjectFormats::countCode だけ（手当が変わるところ）。
            $kind = ProjectFormats::countCode($c['format']) === 'online' ? 'オンライン' : 'リアル';
            $byKindDay[$kind][$c['ymd']] = ($byKindDay[$kind][$c['ymd']] ?? 0) + 1;
        }
        foreach (['オンライン', 'リアル'] as $kind) {
            $groups = [];
            foreach ($byKindDay[$kind] ?? [] as $ymd => $n) {
                $groups[min($n, 3)][] = $ymd;
            }
            ksort($groups);
            foreach ($groups as $n => $ymds) {
                $price = (int) (self::BASE[$kind] * [1 => 1, 2 => 1.5, 3 => 2][$n]);
                $items[] = ['label' => $kind.'イベント'.($n > 1 ? $n.'案件/日' : '').'：'.$days($ymds),
                    'qty' => count($ymds).'日', 'price' => $price, 'amount' => $price * count($ymds)];
            }
        }

        // 手で入れた手当（「手当の記録」・2026-10-13）。$allowances は StaffAllowances::row の形。
        // ⚠ MC・ロング・前日設営・事前リハーサル・宿泊はアサインから自動でも数える＝**自動で数えた日と同じ日の手入力は足さない**
        //   （二重に数えない）。重なったものは「要確認」で知らせる。自動で拾えない日（アサインに無い仕事）だけ足す。
        $byKind = [];
        foreach ($allowances as $a) {
            $byKind[$a['kind']][] = $a;
        }
        $overlap = [];
        $addManualDays = function (array &$perDay, string $kind) use ($byKind, &$overlap, $md) {
            foreach ($byKind[$kind] ?? [] as $a) {
                if (isset($perDay[$a['date']])) {
                    $overlap[] = $kind.' '.$md($a['date']);

                    continue;
                }
                $perDay[$a['date']] = $a['count'];
            }
        };

        // ② MC手当（案件ごと・同じ日に2案件なら「(2案件)」）。
        $mc = [];
        foreach ($main as $c) {
            if ($c['roleCode'] === AssignmentRole::MC) {
                $mc[$c['ymd']] = ($mc[$c['ymd']] ?? 0) + 1;
            }
        }
        $addManualDays($mc, 'MC手当');
        if ($mc) {
            ksort($mc);
            $label = implode('、', array_map(fn ($y, $n) => $md($y).($n > 1 ? '('.$n.'案件)' : ''), array_keys($mc), $mc));
            $n = array_sum($mc);
            $items[] = ['label' => 'MC手当：'.$label, 'qty' => $n.'案件', 'price' => self::MC, 'amount' => self::MC * $n];
        }

        // ③ ロング手当（実施形態がリアルロング）。
        $long = array_fill_keys(array_unique(array_map(fn ($c) => $c['ymd'], array_filter($main, fn ($c) => ProjectFormats::countCode($c['format']) === 'long'))), 1);
        $addManualDays($long, 'ロング手当');
        if ($long) {
            $n = array_sum($long);
            $items[] = ['label' => 'ロング手当：'.$days(array_keys($long)), 'qty' => $n.'回', 'price' => self::LONG, 'amount' => self::LONG * $n];
        }

        // ④ 前日設営手当・⑤ 事前リハーサル手当・6時間以上＝1案件は、**手当の記録だけを見る**（2026-10-13 baba）。
        //   アサインの前日設営・リハ日は StaffAllowances::syncAuto が手当の記録へ自動で入れる＝ここで数え直さない
        //   （消した・種類を切り替えた、を正しく反映するため）。
        $prepRows = $byKind['前日設営手当'] ?? [];
        if ($prepRows) {
            $perDay = [];
            foreach ($prepRows as $a) {
                $perDay[$a['date']] = ($perDay[$a['date']] ?? 0) + $a['count'];
            }
            $n = array_sum($perDay);
            $items[] = ['label' => '前日設営手当：'.$days(array_keys($perDay)), 'qty' => $n.'回', 'price' => self::PREP, 'amount' => self::PREP * $n];
        }
        foreach ($byKind['事前リハーサル手当'] ?? [] as $a) {
            $what = $a['project'] !== '' ? $a['project'] : $a['note'];
            $items[] = ['label' => '事前リハーサル手当：'.$md($a['date']).($what !== '' ? '（'.$what.'リハーサル）' : ''), 'qty' => $a['count'].'回', 'price' => $a['unit'], 'amount' => $a['amount']];
        }
        foreach ($byKind[StaffAllowances::SIX_HOURS] ?? [] as $a) {
            $kindName = $a['unit'] === self::BASE['オンライン'] ? 'オンライン' : 'リアル';
            $what = $a['project'] !== '' ? $a['project'] : $a['note'];
            $items[] = ['label' => $kindName.'イベント（6時間以上のリハ・設営）：'.$md($a['date']).($what !== '' ? '（'.$what.'）' : ''),
                'qty' => $a['count'].'日', 'price' => $a['unit'], 'amount' => $a['amount']];
        }

        // ⑥ 宿泊（前泊）手当（案件の宿泊が前泊あり）。前泊した日＝前日。＋手入力（後泊など）。
        $stayDays = [];
        foreach ($main as $c) {
            if (Lodging::hasPreStay($c['lodging'])) {
                $prev = Carbon::parse($c['ymd'])->subDay()->format('Y-m-d');
                $stayDays[$prev] = 1;
                $items[] = ['label' => '前泊手当：'.$md($prev).'（'.$md($c['ymd']).'本番の'.$c['name'].'のため）'
                    .(str_contains($c['lodging'], '一部') ? '※一部前泊＝本人が泊まったか確認' : ''),
                    'qty' => '1回', 'price' => self::STAY, 'amount' => self::STAY];
            }
        }
        foreach ($byKind['宿泊手当'] ?? [] as $a) {
            if (isset($stayDays[$a['date']])) {
                $overlap[] = '宿泊手当 '.$md($a['date']);

                continue;
            }
            $what = $a['note'] !== '' ? $a['note'] : $a['project'];
            $items[] = ['label' => '宿泊手当：'.$md($a['date']).($what !== '' ? '（'.$what.'）' : ''), 'qty' => $a['count'].'回', 'price' => $a['unit'], 'amount' => $a['amount']];
        }

        // ⑦ 手で入れるだけの手当（運転・スライド・事前レクチャー・大型・その他）。
        //   運転・スライドは日付を並べて1行、ほかは1件1行。
        foreach (['運転手当', 'スライド手当'] as $kind) {
            if (empty($byKind[$kind])) {
                continue;
            }
            $rows = $byKind[$kind];
            $n = array_sum(array_column($rows, 'count'));
            $unit = $rows[0]['unit'];
            $perDay = [];
            foreach ($rows as $a) {
                $perDay[$a['date']] = ($perDay[$a['date']] ?? 0) + $a['count'];
            }
            ksort($perDay);
            $label = implode('、', array_map(fn ($y, $c) => $md($y).($c > 1 ? '('.$c.'回)' : ''), array_keys($perDay), $perDay));
            $items[] = ['label' => $kind.'：'.$label, 'qty' => $n.($kind === 'スライド手当' ? '案件' : '回'), 'price' => $unit, 'amount' => $unit * $n];
        }
        foreach (['事前レクチャー手当', '大型手当', 'その他'] as $kind) {
            foreach ($byKind[$kind] ?? [] as $a) {
                $what = $a['project'] !== '' ? $a['project'] : $a['note'];
                $name = $kind === 'その他' ? ($a['note'] !== '' ? $a['note'] : 'その他') : $kind;
                $items[] = ['label' => $name.'：'.$md($a['date']).($kind !== 'その他' && $what !== '' ? '（'.$what.'）' : ''),
                    'qty' => $a['count'].'回', 'price' => $a['unit'], 'amount' => $a['amount']];
            }
        }

        // ECSでは分からないもの＝見る人が確かめる。
        $checks[] = '交通費：最寄り駅'.($station !== '' ? '「'.$station.'」' : '（名簿に未入力）').'から。往復'.number_format(self::TRAVEL_CAP).'円までに収まっているか（税区分は対象外）';
        $checks[] = $allowances
            ? '運転・スライド・事前レクチャー・大型手当は「手当の記録」に入っているぶんだけ数えています'
            : '運転手当（20分以上運転）・スライド手当・事前レクチャー手当などは「手当の記録」にまだ入っていません（請求書にあれば確かめて入れてください）';
        if ($overlap) {
            $checks[] = '手当の記録のうち、アサインから自動で数えた日と同じ日のものは二重に数えていません：'.implode('、', $overlap);
        }
        $cancelled = array_values(array_filter($cases, fn ($c) => $c['cancelled']));
        if ($cancelled) {
            $checks[] = 'キャンセル料の対象かも：'.implode('、', array_map(fn ($c) => $md($c['ymd']).' '.$c['name'], $cancelled))
                .'（前日＝1/3・当日＝1/2・集合1時間前を過ぎてから＝全額＋交通費／スタッフ都合は対象外）';
        }
        $spare = array_filter($live, fn ($c) => $c['dayType'] === '予備日');
        if ($spare) {
            $checks[] = '予備日のアサインが'.count($spare).'件あります（実施したかどうか確認）';
        }
        $tentative = array_filter($live, fn ($c) => $c['status'] === '仮');
        if ($tentative) {
            $checks[] = 'まだ「仮」のアサインが'.count($tentative).'件あります（本当に出たか確認）';
        }

        return ['items' => $items, 'total' => array_sum(array_column($items, 'amount')), 'checks' => $checks];
    }
}
