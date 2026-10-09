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
 * 見る・直す＝社員以上（新人が自分で直せるように・2026-10-09 baba決定）。門はルートの tier:employee。
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
            // OJT担当に選べる人＝在籍中の社員（拠点を問わない）。
            'employees' => Person::employees()->where('active', true)->orderBy('id')->get(['id', 'name']),
            'targets' => Rookies::targets(),
            'difficulties' => ContentDifficulty::orderBy('kind', 'desc')->orderBy('id')->get(),
            'contents' => Content::where('active', true)->orderBy('content_name')->get(['id', 'content_name']),
            'unlinked' => $plan['unlinked'],
            'canEdit' => true,   // 社員以上ならだれでも直せる（この画面に来られるのは社員以上だけ）
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

    /** OJT担当とひとことメモ（2026-10-09 baba要望）。 */
    public function setOjt(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'exists:people,id'],
            'ojt' => ['nullable', 'string', 'exists:people,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $p = Person::findOrFail($data['id']);
        $p->rookie_ojt_id = $data['ojt'] ?: null;
        $p->rookie_note = $data['note'] ?? null;
        $p->save();

        return back()->with('ok', $p->name.'さんのOJT担当・メモを保存しました。');
    }

    /**
     * 経験を手で直す（2026-10-09 baba「大型で受付だったから実は経験していない、みたいなことがある」）。
     * 受け取り：id＋ov[コンテンツID][fc|d]＝''（アサインから自動）／done（やった）／none（やっていない）。
     */
    public function setExp(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'exists:people,id'],
            'ov' => ['nullable', 'array'],
            'ov.*.fc' => ['nullable', 'in:done,none'],
            'ov.*.d' => ['nullable', 'in:done,none'],
        ]);
        $out = [];
        foreach (($data['ov'] ?? []) as $cid => $o) {
            $o = array_filter(['fc' => $o['fc'] ?? null, 'd' => $o['d'] ?? null]);
            if ($o) {
                $out[(string) $cid] = $o;
            }
        }
        $p = Person::findOrFail($data['id']);
        $p->rookie_overrides = $out ?: null;
        $p->save();

        return back()->with('ok', $p->name.'さんの経験を保存しました。');
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
        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);   // 画面はその場で保存する（読み込み直さない・2026-10-09）
        }

        return back()->with('ok', 'コンテンツをつなぎました。');
    }
}
