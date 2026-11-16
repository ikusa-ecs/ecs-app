<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\SfProduct;
use App\Support\RoleRequirementCsv;
use App\Support\SfProductCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SFの商品 ↔ ECSのコンテンツの対応表の画面（/sf-products・管理者以上）。2026-11-16。
 *
 * 205件の商品を1件ずつ「ECSのコンテンツを選ぶ／無視する／未対応に戻す」で仕分ける道具。
 * ⚠ 名前が近いコンテンツは「候補」として出すだけ。自動では決めない（決めるのは人）。
 */
class SfProductController extends Controller
{
    public function index(Request $request)
    {
        $filter = (string) $request->query('show', 'pending');
        if (! in_array($filter, ['all', SfProduct::PENDING, SfProduct::CONTENT, SfProduct::IGNORE, 'hint'], true)) {
            $filter = 'pending';
        }
        $family = trim((string) $request->query('family', ''));
        $q = trim((string) $request->query('q', ''));

        $query = SfProduct::query();
        if ($filter === 'hint') {
            // 「コンテンツ以外かも」の印があって、まだ決めていないもの
            $query->whereNotNull('hint')->where('handling', SfProduct::PENDING);
        } elseif ($filter !== 'all') {
            $query->where('handling', $filter);
        }
        if ($family !== '') {
            $query->where('family', $family);
        }
        if ($q !== '') {
            $query->where('name', 'like', '%'.$q.'%');
        }
        $rows = $query->orderBy('family')->orderBy('name')->get();

        $contents = Content::where('active', true)->orderBy('content_name')->get(['id', 'content_name']);
        $contentNames = Content::pluck('content_name', 'id')->all();

        return view('sf_products', [
            'rows' => $rows,
            'filter' => $filter,
            'tabs' => ['pending' => '未対応', 'hint' => 'コンテンツ以外かも（未対応）', 'content' => 'コンテンツにした', 'ignore' => '無視にした', 'all' => 'ぜんぶ'],
            'family' => $family,
            'q' => $q,
            'families' => SfProduct::whereNotNull('family')->distinct()->orderBy('family')->pluck('family')->all(),
            'counts' => [
                'all' => SfProduct::count(),
                SfProduct::PENDING => SfProduct::where('handling', SfProduct::PENDING)->count(),
                SfProduct::CONTENT => SfProduct::where('handling', SfProduct::CONTENT)->count(),
                SfProduct::IGNORE => SfProduct::where('handling', SfProduct::IGNORE)->count(),
                'hint' => SfProduct::whereNotNull('hint')->where('handling', SfProduct::PENDING)->count(),
            ],
            'contents' => $contents->map(fn ($c) => ['id' => $c->id, 'name' => $c->content_name])->values()->all(),
            'contentNames' => $contentNames,
            'suggest' => $this->suggestions($rows, $contents),
        ]);
    }

    /**
     * 名前が近いECSのコンテンツ（候補・最大3件）。
     * 商品名の末尾の（ファミリー名）を外し、書き方の違いを無視して、同じ名前・片方がもう片方を含む ものを拾う。
     * ⚠ 候補を出すだけで、決めるのは人。
     *
     * @return array<string, list<array{id: string, name: string}>>
     */
    private function suggestions($rows, $contents): array
    {
        $norm = [];
        foreach ($contents as $c) {
            $n = RoleRequirementCsv::normalizeName((string) $c->content_name);
            if (mb_strlen($n) >= 2) {
                $norm[] = ['id' => $c->id, 'name' => $c->content_name, 'n' => $n];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            if ($r->handling !== SfProduct::PENDING) {
                continue;
            }
            $base = preg_replace('/（[^（）]*）\s*\z/u', '', (string) $r->name) ?? (string) $r->name;
            $bn = RoleRequirementCsv::normalizeName($base);
            if (mb_strlen($bn) < 2) {
                continue;
            }
            $exact = [];
            $near = [];
            foreach ($norm as $c) {
                if ($c['n'] === $bn) {
                    $exact[] = ['id' => $c['id'], 'name' => $c['name']];
                } elseif (str_contains($c['n'], $bn) || str_contains($bn, $c['n'])) {
                    $near[] = ['id' => $c['id'], 'name' => $c['name']];
                }
            }
            $list = array_slice(array_merge($exact, $near), 0, 3);
            if ($list !== []) {
                $out[$r->sf_product_id] = $list;
            }
        }

        return $out;
    }

    /** 1行ぶんの扱いをその場で保存（画面から fetch で呼ぶ）。 */
    public function save(Request $request)
    {
        $data = $request->validate([
            'sf_product_id' => ['required', 'string', 'size:18'],
            'handling' => ['required', 'in:pending,content,ignore'],
            'content_id' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = SfProduct::where('sf_product_id', $data['sf_product_id'])->first();
        if (! $row) {
            return response()->json(['ok' => false, 'message' => 'この商品が対応表に見つかりません。'], 404);
        }

        $contentId = null;
        if ($data['handling'] === SfProduct::CONTENT) {
            $content = Content::find((string) ($data['content_id'] ?? ''));
            if (! $content) {
                return response()->json(['ok' => false, 'message' => 'ECSのコンテンツを選んでください。'], 422);
            }
            $contentId = $content->id;
        }

        $row->handling = $data['handling'];
        $row->content_id = $contentId;
        if ($request->has('note')) {
            $row->note = $data['note'] ?? null;
        }
        $row->updated_by = Auth::id();
        $row->save();

        return response()->json([
            'ok' => true,
            'handling' => $row->handling,
            'label' => SfProduct::LABELS[$row->handling],
            'pending' => SfProduct::where('handling', SfProduct::PENDING)->count(),
        ]);
    }

    /** CSVの取込（アップロード、またはECSに入っている205件のCSV）。 */
    public function import(Request $request)
    {
        if ($request->hasFile('csv')) {
            $request->validate(['csv' => ['file', 'mimes:csv,txt', 'max:2048']]);
            $raw = (string) file_get_contents($request->file('csv')->getRealPath());
        } elseif ($request->boolean('bundled')) {
            $raw = (string) @file_get_contents(SfProductCsv::bundledPath());
        } else {
            return redirect('/sf-products')->with('status', 'CSVファイルを選んでください。');
        }

        $r = SfProductCsv::import($raw);
        $msg = "取り込みました：新しい商品 {$r['created']}件／更新 {$r['updated']}件（すでに決めた扱いはそのまま）。";
        if ($r['skipped'] !== []) {
            $msg .= ' 読めなかった行：'.implode(' / ', array_slice($r['skipped'], 0, 10));
        }

        return redirect('/sf-products?show=all')->with('status', $msg);
    }
}
