<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Models\Office;
use App\Support\AssignmentRole;
use Illuminate\Http\Request;

/**
 * マスタ管理（/masters）。設定画面の「管理する」から開く。
 *
 * ・コンテンツ（contents）… 追加・編集・削除できる
 * ・拠点（offices）        … 追加・編集・削除できる
 * ・ポジション（役割）      … 一覧表示のみ。役割コードはシステムの土台（正本 AssignmentRole）
 *                            のため、画面からは変更しない（追加・変更は開発対応）。
 */
class MasterController extends Controller
{
    public function index()
    {
        return view('masters', [
            'contents' => Content::orderBy('id')->get(),
            'offices' => Office::orderBy('sort_order')->orderBy('id')->get(),
            // 役割は正本のコード→ラベル。編集不可（表示のみ）。
            'positions' => AssignmentRole::LABELS,
        ]);
    }

    // ── コンテンツ ──────────────────────────────────────────

    public function contentStore(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'string'],
            'content_name' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'sheets_per_team' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $content = ($data['id'] ?? null)
            ? Content::find($data['id'])
            : new Content(['id' => $this->nextContentId()]);

        if (! $content) {
            return back()->with('status', 'コンテンツが見つかりませんでした。');
        }

        $content->content_name = $data['content_name'];
        $content->category = $data['category'] ?? null;
        $content->is_physical = $request->boolean('is_physical');
        $content->needs_paper = $request->boolean('needs_paper');
        $content->sheets_per_team = max(1, (int) ($data['sheets_per_team'] ?? 1));
        $content->active = $request->boolean('active');
        $content->save();

        return redirect('/masters#contents')
            ->with('status', 'コンテンツを保存しました：' . $content->content_name);
    }

    /** コンテンツを一括保存（既存の全行をまとめて更新）。物品(is_physical)は触らない＝画面から廃止。 */
    public function contentBulkStore(Request $request)
    {
        $rows = $request->input('rows', []);
        $n = 0;
        foreach ($rows as $id => $vals) {
            $content = Content::find($id);
            if (! $content) {
                continue;
            }
            $content->content_name = trim($vals['content_name'] ?? $content->content_name) ?: $content->content_name;
            $content->category = $vals['category'] ?? null;
            $content->needs_paper = ! empty($vals['needs_paper']);
            $content->sheets_per_team = max(1, (int) ($vals['sheets_per_team'] ?? 1));
            $content->active = ! empty($vals['active']);
            $content->save();
            $n++;
        }

        return redirect('/masters#contents')->with('status', "コンテンツを{$n}件まとめて保存しました。");
    }

    public function contentDestroy(string $id)
    {
        $content = Content::find($id);
        if ($content) {
            $content->delete();
            // 必要人数の設定も一緒に消す（孤立データを残さない）。
            ContentRoleRequirement::where('content_id', $id)->delete();
        }

        return redirect('/masters#contents')->with('status', 'コンテンツを削除しました。');
    }

    /** コンテンツ別・規模別の必要ポジション人数の一覧（規模×ポジションのグリッド）。 */
    public const REQ_SCALES = ['小型', '中型', '大型'];

    public function contentReqs(string $id)
    {
        $content = Content::find($id);
        if (! $content) {
            return redirect('/masters#contents')->with('status', 'コンテンツが見つかりませんでした。');
        }

        // 既存の設定を「規模|ポジション => 人数」に。
        $saved = ContentRoleRequirement::where('content_id', $id)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->scale . '|' . $r->position => $r->count])
            ->all();

        return view('content_requirements', [
            'content' => $content,
            'scales' => self::REQ_SCALES,
            'positions' => AssignmentRole::positionLabels(),   // コード => ラベル
            'saved' => $saved,
        ]);
    }

    public function contentReqsSave(Request $request, string $id)
    {
        $content = Content::find($id);
        if (! $content) {
            return redirect('/masters#contents')->with('status', 'コンテンツが見つかりませんでした。');
        }

        // req[規模][ポジション] = 人数。0（または空）は保存せず、既存があれば消す。
        $req = $request->input('req', []);
        foreach (self::REQ_SCALES as $scale) {
            foreach (array_keys(AssignmentRole::positionLabels()) as $pos) {
                $count = (int) ($req[$scale][$pos] ?? 0);
                if ($count > 0) {
                    ContentRoleRequirement::updateOrCreate(
                        ['content_id' => $id, 'scale' => $scale, 'position' => $pos],
                        ['count' => $count]
                    );
                } else {
                    ContentRoleRequirement::where('content_id', $id)
                        ->where('scale', $scale)->where('position', $pos)->delete();
                }
            }
        }

        return redirect('/masters/contents/' . $id . '/requirements')
            ->with('status', '「' . $content->content_name . '」の必要人数を保存しました。');
    }

    /** 次のコンテンツID（CT-001 形式）。 */
    private function nextContentId(): string
    {
        $last = Content::where('id', 'like', 'CT-%')
            ->orderByRaw('CAST(SUBSTR(id, 4) AS INTEGER) DESC')
            ->value('id');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'CT-' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    // ── 拠点（事務所）──────────────────────────────────────

    /** 拠点を新規追加（並び順は末尾に自動採番）。 */
    public function officeStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $office = new Office();
        $office->name = $data['name'];
        $office->sort_order = (int) (Office::max('sort_order') ?? 0) + 10;   // 末尾に足す
        $office->active = $request->boolean('active');
        $office->save();

        return redirect('/masters#offices')
            ->with('status', '拠点を追加しました：' . $office->name);
    }

    /** 拠点を一括保存（名前・有効をまとめて更新）。並び順は上下ボタンで管理。 */
    public function officeBulkStore(Request $request)
    {
        $n = $this->saveOfficeRows($request);

        return redirect('/masters#offices')->with('status', "拠点を{$n}件まとめて保存しました。");
    }

    /** 拠点の並び順を1つ上下に入れ替える（編集中の名前・有効も先に保存する）。 */
    public function officeMove(Request $request, int $id, string $dir)
    {
        $this->saveOfficeRows($request);   // 編集中の内容を先に保存

        $ordered = Office::orderBy('sort_order')->orderBy('id')->get();
        $pos = $ordered->search(fn ($o) => $o->id === $id);
        if ($pos !== false) {
            $swapWith = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swapWith >= 0 && $swapWith < $ordered->count()) {
                $a = $ordered[$pos];
                $b = $ordered[$swapWith];
                [$a->sort_order, $b->sort_order] = [$b->sort_order, $a->sort_order];
                $a->save();
                $b->save();
            }
        }

        return redirect('/masters#offices');
    }

    public function officeDestroy(int $id)
    {
        $office = Office::find($id);
        if ($office) {
            $office->delete();
        }

        return redirect('/masters#offices')->with('status', '拠点を削除しました。');
    }

    /** 一括フォームの拠点行（名前・有効）を保存。保存件数を返す。 */
    private function saveOfficeRows(Request $request): int
    {
        $rows = $request->input('rows', []);
        $n = 0;
        foreach ($rows as $id => $vals) {
            $office = Office::find($id);
            if (! $office) {
                continue;
            }
            $name = trim($vals['name'] ?? '');
            if ($name !== '') {
                $office->name = $name;
            }
            $office->active = ! empty($vals['active']);
            $office->save();
            $n++;
        }

        return $n;
    }
}
