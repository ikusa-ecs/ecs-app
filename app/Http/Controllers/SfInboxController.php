<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Project;
use App\Models\SfInboxItem;
use App\Models\SfProduct;
use App\Support\OfficeScope;
use App\Support\SfInboxOwner;
use App\Support\SfInboxRegister;
use App\Support\SfOffice;
use App\Support\SfOpportunityMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Salesforceの商談の受信箱（/sf-inbox・管理者以上）。2026-11-16。
 *
 * ⚠ 届いただけでは案件は変わらない。「案件として登録」を押したときだけ projects に1件作る
 *   （中身は App\Support\SfInboxRegister）。見送りは消さずに残す。
 * ⚠ 拠点ごとに分ける（2026-11-20 小沼さん）。はじめは自分の拠点・切り替えでほかの拠点／全拠点（OfficeScope）。
 *   ただし拠点が読めない（要確認の）商談は、どの拠点を選んでいても「拠点が分からない商談」として出す
 *   （どこか1拠点に寄せると、ほかの拠点の人が見落とすため）。
 * ⚠ 担当営業のマイページからも、本人あての商談に限って登録・見送りできる（registerMine／skipMine・2026-11-20）。
 */
class SfInboxController extends Controller
{
    public function index(Request $request)
    {
        $show = (string) $request->query('show', SfInboxItem::WAITING);
        if (! in_array($show, [SfInboxItem::WAITING, SfInboxItem::REGISTERED, SfInboxItem::SKIPPED, 'all'], true)) {
            $show = SfInboxItem::WAITING;
        }
        // 絞る拠点（null＝全拠点）。はじめは自分の拠点。
        $officeScope = OfficeScope::filter($request);

        $items = SfInboxItem::query()->orderByDesc('received_at')->orderByDesc('id')->get();

        // 登録ずみの商談は、SFの拠点が読めなくても案件の拠点で分けられる（登録するときに人が選んだので）。
        $projectOffices = Project::whereIn('id', $items->pluck('project_id')->filter()->unique()->values())
            ->pluck('office', 'id');

        $all = $items->map(function (SfInboxItem $item) use ($projectOffices) {
            // ⚠ 1件の中身が壊れていても、画面全体は止めない
            try {
                $preview = SfOpportunityMap::preview($item->payload ?? []);
            } catch (\Throwable $e) {
                $preview = null;
            }
            $office = $preview['office'] ?? null;
            if ($office === null && $item->project_id) {
                $office = trim((string) ($projectOffices[$item->project_id] ?? '')) ?: null;
            }

            return ['item' => $item, 'p' => $preview, 'office' => $office];
        });

        // 選んだ拠点の商談＋拠点が分からない商談。全拠点なら全部。
        $scoped = $all->filter(fn ($r) => $officeScope === null || $r['office'] === null || $r['office'] === $officeScope)->values();

        $shown = $show === 'all' ? $scoped : $scoped->filter(fn ($r) => $r['item']->status === $show)->values();

        $offices = OfficeScope::options();
        if ($offices === []) {
            $offices = SfOffice::ECS_OFFICES;
        }

        $countOf = fn (string $status) => $scoped->filter(fn ($r) => $r['item']->status === $status)->count();

        return view('sf_inbox', [
            // 拠点が分からない商談（どの拠点でも出す）と、拠点が分かる商談を分けて渡す。
            'unknownRows' => $shown->filter(fn ($r) => $r['office'] === null)->values(),
            'rows' => $shown->filter(fn ($r) => $r['office'] !== null)->values(),
            'show' => $show,
            'offices' => $offices,
            'officeScope' => $officeScope,
            // タブ・ボタンのリンクに付ける拠点（「全拠点」は 'all'＝空文字にしない）。
            'officeParam' => OfficeScope::param($officeScope),
            'counts' => [
                SfInboxItem::WAITING => $countOf(SfInboxItem::WAITING),
                SfInboxItem::REGISTERED => $countOf(SfInboxItem::REGISTERED),
                SfInboxItem::SKIPPED => $countOf(SfInboxItem::SKIPPED),
                'all' => $scoped->count(),
            ],
            'pendingProducts' => SfProduct::where('handling', SfProduct::PENDING)->count(),
        ]);
    }

    /** 案件として登録（このときだけ projects が増える）。 */
    public function register(Request $request, int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        $r = SfInboxRegister::register($item, $request->input('office'), Auth::id());

        return redirect(self::backUrl($request))->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** 見送り（消さずに残す）。取り込み待ちのものだけ。 */
    public function skip(Request $request, int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        $r = self::doSkip($item);

        return redirect(self::backUrl($request))->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /**
     * 取り込み待ちに戻す。見送りのものだけ。
     * ⚠ 登録ずみは戻さない（戻すと同じ商談から案件が2つできる）。
     */
    public function reopen(Request $request, int $id)
    {
        $item = SfInboxItem::findOrFail($id);
        if ($item->status !== SfInboxItem::SKIPPED) {
            return redirect(self::backUrl($request, $item->status))->with('error', '見送りの商談だけ取り込み待ちに戻せます（登録ずみの商談は戻せません）。');
        }
        $item->update(['status' => SfInboxItem::WAITING, 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return redirect(self::backUrl($request, SfInboxItem::SKIPPED))->with('status', '取り込み待ちに戻しました。');
    }

    /**
     * マイページから「案件として登録」（本人あての商談だけ・2026-11-20）。
     * ⚠ 本人かどうかは必ずここ（サーバー側）で確かめる。ボタンを隠すだけだと、URLを打てば他人の商談も登録できてしまう。
     * ⚠ 登録そのものは受信箱と同じ SfInboxRegister を通す（二重に登録しない仕組みを1か所に保つ）。
     */
    public function registerMine(Request $request, int $id)
    {
        $item = $this->mineOrAbort($id);
        // 営業担当には必ず本人が入るように、メールで名前が決まらないときは押した本人の名前を渡す（2026-11-20 小沼さん）。
        $r = SfInboxRegister::register($item, $request->input('office'), Auth::id(), (string) (Auth::user()->name ?? ''));

        // 登録できたら、知らせの横に「編集画面を開く」を出せるように案件のIDも渡す（2026-11-20 小沼さん）。
        return redirect('/mypage')->with($r['ok'] ? 'sf_status' : 'sf_error', $r['message'])
            ->with('sf_project_id', $r['ok'] ? ($r['project_id'] ?? null) : null);
    }

    /** マイページから「見送り」（本人あての商談だけ・2026-11-20）。 */
    public function skipMine(int $id)
    {
        $item = $this->mineOrAbort($id);
        $r = self::doSkip($item);

        return redirect('/mypage')->with($r['ok'] ? 'sf_status' : 'sf_error', $r['message']);
    }

    /**
     * マイページから、見送った商談を「取り込み待ち」に戻す（本人あての商談だけ・2026-11-20 小沼さん）。
     * 営業さんが「まだ決まっていないので、いったん見送り」と押したあと、自分で戻せるようにする（それまでは管理者しか戻せなかった）。
     * ⚠ 登録ずみは戻さない（戻すと同じ商談から案件が2つできる）。
     */
    public function reopenMine(int $id)
    {
        $item = $this->mineOrAbort($id);
        if ($item->status !== SfInboxItem::SKIPPED) {
            return redirect('/mypage')->with('sf_error', '見送りの商談だけ戻せます。');
        }
        $item->update(['status' => SfInboxItem::WAITING, 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return redirect('/mypage')->with('sf_status', '取り込み待ちに戻しました。');
    }

    /** 本人あての商談でなければ 403（管理者でも、マイページの入口からは本人あてだけ）。 */
    private function mineOrAbort(int $id): SfInboxItem
    {
        $item = SfInboxItem::findOrFail($id);
        $me = Auth::user();
        abort_unless($me instanceof Person && SfInboxOwner::isOwner($item, $me), 403, 'あなたが担当営業の商談ではありません。');

        return $item;
    }

    /** @return array{ok: bool, message: string} */
    private static function doSkip(SfInboxItem $item): array
    {
        if ($item->status !== SfInboxItem::WAITING) {
            return ['ok' => false, 'message' => '取り込み待ちの商談だけ見送りにできます。'];
        }
        $item->update(['status' => SfInboxItem::SKIPPED, 'decided_by' => Auth::id(), 'decided_at' => now()]);

        return ['ok' => true, 'message' => '見送りにしました（消えずに残っています。あとで取り込み待ちに戻せます）。'];
    }

    /**
     * 押したあとに戻る先。見ていたタブ（show）と拠点（view_office）をフォームから受け取って付け直す
     * （付けないと、ほかの拠点を見ていたのに押すたびに自分の拠点へ戻ってしまう）。
     * ⚠ 拠点の値はそのまま信じず OfficeScope::fromValue を通す（一般社員なら自拠点に固定される）。
     */
    private static function backUrl(Request $request, ?string $defaultShow = null): string
    {
        $q = [];
        $show = (string) $request->input('show', $defaultShow ?? '');
        if (in_array($show, [SfInboxItem::WAITING, SfInboxItem::REGISTERED, SfInboxItem::SKIPPED, 'all'], true)) {
            $q['show'] = $show;
        }
        if (trim((string) $request->input('view_office', '')) !== '') {
            $q['office'] = OfficeScope::param(OfficeScope::fromValue($request->input('view_office')));
        }

        return '/sf-inbox'.($q ? '?'.http_build_query($q) : '');
    }
}
