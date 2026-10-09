<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\ContentDifficulty;
use App\Models\Person;
use App\Support\OfficeScope;
use App\Support\RookieDifficulty;
use App\Support\RookieFcPlan;
use App\Support\Rookies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 新人ページ（/rookies・2026-10-09 baba要望）。
 * 見る＝社員以上／卒業・目標・難易度表を直す＝管理者以上。
 * ⚠ FCの案は**見るだけ**（ここからアサインは保存しない）。中身の正本＝App\Support\RookieFcPlan。
 */
class RookieController extends Controller
{
    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01')->startOfDay()
            : Carbon::today()->startOfMonth();

        $plan = RookieFcPlan::build($month, $office);

        return view('rookies', [
            'month' => $month,
            'office' => $office,
            'rookies' => $plan['rookies'],
            'picks' => $plan['picks'],
            'graduated' => Rookies::graduated($office),
            'others' => OfficeScope::applyToPeople(Person::employees()->where('active', true), $office)
                ->orderBy('id')->get(['id', 'name'])
                ->reject(fn ($p) => collect($plan['rookies'])->contains('id', $p->id))->values(),
            'targets' => Rookies::targets(),
            'difficulties' => ContentDifficulty::orderBy('kind', 'desc')->orderBy('id')->get(),
            'contents' => Content::where('active', true)->orderBy('content_name')->get(['id', 'content_name']),
            'unlinked' => $plan['unlinked'],
            'canEdit' => in_array(optional($request->user())->permission, ['manager', 'admin'], true),
        ]);
    }

    /** 卒業（out）／新人に入れる（in）／元に戻す（空＝自動）。 */
    public function setState(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'exists:people,id'],
            'state' => ['nullable', 'in:in,out'],
        ]);
        $p = Person::findOrFail($data['id']);
        $p->rookie_state = $data['state'] ?? null;
        $p->save();

        return back()->with('ok', $p->name.'さんを'.match ($p->rookie_state) {
            Rookies::OUT => '卒業（独り立ち）にしました。',
            Rookies::IN => '新人に入れました。',
            default => '元に戻しました。',
        });
    }

    /** 何ヶ月目かを数える起点。 */
    public function setSince(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'exists:people,id'],
            'since' => ['nullable', 'date'],
        ]);
        $p = Person::findOrFail($data['id']);
        $p->rookie_since = $data['since'] ?: null;
        $p->save();

        return back()->with('ok', $p->name.'さんの起点を直しました。');
    }

    public function setTargets(Request $request)
    {
        Rookies::saveTargets((array) $request->input('t', []));

        return back()->with('ok', '月ごとの目標を保存しました。');
    }

    /** 「コンテンツ難易度」CSVを取り込む（リアル／オンライン）。 */
    public function import(Request $request)
    {
        $request->validate([
            'kind' => ['required', 'in:'.RookieDifficulty::REAL.','.RookieDifficulty::ONLINE],
            'file' => ['required', 'file', 'max:2048'],
        ]);
        $r = RookieDifficulty::import((string) file_get_contents($request->file('file')->getRealPath()), $request->input('kind'));

        return back()->with('ok', $request->input('kind').'の難易度を'.$r['saved'].'件 取り込みました（台帳につながった '
            .$r['linked'].'件・まだつながっていない '.$r['unlinked'].'件）。');
    }

    /** 難易度の行を台帳のコンテンツにつなぐ（空＝外す）。 */
    public function link(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:content_difficulties,id'],
            'content_id' => ['nullable', 'string', 'exists:contents,id'],
        ]);
        ContentDifficulty::whereKey($data['id'])->update(['content_id' => $data['content_id'] ?: null]);

        return back()->with('ok', 'コンテンツをつなぎました。');
    }
}
