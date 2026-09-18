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

    /**
     * 決まりの設定を保存する（POST /active-bonus/settings）。
     *
     * ⚠ 2026-09-18 に共通設定（/settings）からこの画面へ移した（baba要望
     *   「1つの画面に設定画面も集約して、常時表示にする」）。保存先のキーは変えていない
     *   ＝共通設定で入れていた値はそのまま生きる。
     * ⚠ 計算・保存の中身は App\Support\ActiveBonus が正本。ここでは受け取って渡すだけ。
     */
    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'counts' => ['present', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'rates' => ['present', 'array'],
            'rates.*' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'hours' => ['required', 'integer', 'min:1', 'max:24'],
            'spot_cost' => ['required', 'integer', 'min:0', 'max:1000000'],
            // スタッフ画面に出すか（2026-09-18 から「実施中」ではなくこの意味）。
            'show_to_staff' => ['nullable'],
        ]);

        // 回数と単価は同じ行どうしを組にする。どちらかが空の行は入れない（書きかけを保存しない）。
        $tiers = [];
        foreach ($data['counts'] as $i => $count) {
            $rate = $data['rates'][$i] ?? null;
            if ($count !== null && $rate !== null) {
                $tiers[] = ['count' => (int) $count, 'rate' => (int) $rate];
            }
        }

        if ($tiers === []) {
            return back()->with('active_bonus_status', '階層を1つも読み取れませんでした。回数と上がる時給の両方を入れてください。');
        }

        ActiveBonus::save(
            $tiers,
            (int) $data['hours'],
            (int) $data['spot_cost'],
            (bool) ($data['show_to_staff'] ?? false),
        );

        return back()->with('active_bonus_status', '繁忙期ボーナスの決まりを保存しました。');
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
