<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * マイページ（S-015 / /mypage）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）と、Blade内ベタ書きの
 * MY_ASSIGN（案件ID→自分のポジション）をブラウザで読んで描いていた。
 * ここでは DB から「ログイン中の社員（＝自分）」の情報を読み、
 *   ① アサインされた案件 … assignments（自分が割り当てられた行）
 *   ② 営業担当の案件   … projects（自分が営業担当の案件）
 * を cases.js と「同じ形」に詰め替えて Blade に渡す。
 *
 * ※認証はMTG後。それまでは「自分」を固定で決める（社員 E-007 / baba）。
 *   DB に該当データが無ければ Blade 側が今までの見本表示にフォールバックする。
 */
class MyPageController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // ── ログイン中の社員（＝自分）。認証導入までは E-007（baba）に固定 ──
        $me = Person::where('role', 'employee')
            ->where(fn ($q) => $q->where('id', 'E-007')->orWhere('name', 'baba'))
            ->first();

        // 画面のプロフィール表示・営業担当の突き合わせに使う「自分」の情報
        $meInfo = [
            'id' => $me->id ?? null,
            'name' => $me->name ?? 'baba',
            'email' => $me->email ?? 'baba@ikusa.co.jp',
            'dept' => $me->department ?? 'イベプラ',
        ];

        // ── 全案件を cases.js と同じ形に詰め替える（表示JSをそのまま動かすため）──
        $contentNames = Content::pluck('content_name', 'id');
        $cases = Project::with(['director:id,name'])
            ->orderBy('start_date')
            ->get()
            ->map(fn (Project $p) => $this->toCase($p, $today, $contentNames))
            ->values();

        // ── ① 自分のアサイン（案件ID → 自分のポジション）──
        // assignments の role をそのまま表示文言に使う（'ディレクター' 等）。
        $myAssign = [];
        if ($me) {
            $myAssign = Assignment::where('staff_id', $me->id)
                ->where('status', '!=', 'キャンセル')
                ->get()
                ->mapWithKeys(fn (Assignment $a) => [$a->project_id => $a->role ?: '現場'])
                ->all();
        }

        return view('mypage', [
            'me' => $meInfo,
            'cases' => $cases,
            'myAssign' => $myAssign,
        ]);
    }

    /** Project 1件 → cases.js と同じ形（マイページが使う項目だけ）。 */
    private function toCase(Project $p, Carbon $today, $contentNames): array
    {
        // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。cases.js の off と同じ意味。
        $off = $p->start_date
            ? intdiv(
                $p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp,
                86400
            )
            : 0;

        // 見出し＝登録されたコンテンツ名（複数あれば先頭）。無ければ案件名で代用。
        $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
        $content = $firstContentId
            ? ($contentNames[$firstContentId] ?? $p->project_name)
            : $p->project_name;

        return [
            'id' => $p->id,
            'content' => $content,
            'name' => $p->project_name,
            'client' => $p->client ?? '',
            'place' => $p->location ?? '',
            'placeShort' => $p->location ?? '',
            'meet' => $p->start_time ?? '—',
            'leave' => $p->end_time ?? '—',
            'dir' => $p->director->name ?? '未定',
            // 営業担当（複数あれば先頭）。マイページ②の突き合わせ（c.sales === ME）に使う。
            'sales' => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
            'status' => $p->status ?? '未着手',
            'note' => $p->note ?? '',
            // 終了した案件＝開催日が過去（アーカイブに送る）。下書きは別フラグ。
            'archived' => $off < 0,
            'draft' => $p->status === '下書き',
            'off' => $off,
        ];
    }
}
