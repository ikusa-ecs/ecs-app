<?php

namespace App\Http\Controllers;

use App\Support\ActiveBonus;
use App\Support\OfficeScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 繁忙期ボーナス（/active-bonus）。2026-09-17 baba要望。
 *
 * 「あと1回出てもらえれば、その人にボーナスが付く＝タイミーを頼むより安く済む」を
 * 一目で分かるようにする画面。アサインする人が、誰を優先して声かけすればよいか決めるのに使う。
 *
 * 計算は App\Support\ActiveBonus に置いてある（画面では計算しない）。
 * ⚠ ここで数え直さないこと。スタッフ画面と数字が食い違う事故のもとになる。
 */
class ActiveBonusController extends Controller
{
    /** 月の選択肢をさかのぼる数（13か月＝前年同月まで見られる）。 */
    private const MONTH_CHOICES = 13;

    public function index(Request $request)
    {
        $month = $this->month($request);
        $office = OfficeScope::filter($request);

        $data = ActiveBonus::summary($month, $office);
        $data['months'] = $this->monthOptions();
        $data['scopeOffice'] = $office ?? '';

        return view('active_bonus', $data);
    }

    /** ?month=YYYY-MM（形が違う・未指定なら今月）。 */
    private function month(Request $request): string
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            // 2026-13 のような月は Carbon が繰り上げてしまうので、作れるかを見てから使う。
            try {
                Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();

                return $raw;
            } catch (\Throwable) {
                // 形は合っているが日付として成り立たない＝今月にする。
            }
        }

        return Carbon::today()->format('Y-m');
    }

    /**
     * 月の選択肢（今月から過去へ）。
     *
     * @return list<array{value:string, label:string}>
     */
    private function monthOptions(): array
    {
        $out = [];
        $cur = Carbon::today()->startOfMonth();
        for ($i = 0; $i < self::MONTH_CHOICES; $i++) {
            $out[] = ['value' => $cur->format('Y-m'), 'label' => $cur->format('Y年n月')];
            $cur = $cur->subMonthNoOverflow();
        }

        return $out;
    }
}
