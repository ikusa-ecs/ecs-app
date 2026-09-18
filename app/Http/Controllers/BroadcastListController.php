<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use App\Support\BroadcastKind;
use App\Support\OfficeScope;
use App\Support\ProjectContentName;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * 配信・中継案件一覧（/broadcast-list）。2026-09-18・FBシート No.21（馬場さん）。
 *
 * 要望＝「配信と中継が有の場合だけ表示する、配信・中継案件一覧ページ。
 *        各案件に配信担当を設定できるように」
 *
 * 【決めたこと（2026-09-18 baba）】
 *  ・出すのは**両方**＝通常案件の「配信／中継」と、ARENA場所貸しの「配信・中継＝あり」。
 *    ⚠ 判定は App\Support\BroadcastKind が正本（保存場所が2か所に分かれているため）。
 *  ・配信担当は**全拠点の社員**から選べる ＋ **外部業者は自由入力**。両方入れてもよい。
 *    保存先は projects.broadcast_owner_id / broadcast_owner_name（入口＝POST /projects/cells）。
 *
 * ⚠ この画面は見るだけではない（担当を選ぶとその場で保存する）。
 *   保存の入口は案件一覧と同じ1か所（saveCells）＝拠点チェックもそこで通る。
 */
class BroadcastListController extends Controller
{
    /** 既定で出す期間（今日から何日先まで）。過去も見たいときは ?past=1。 */
    private const DEFAULT_DAYS = 92;

    public function index(Request $request)
    {
        $office = OfficeScope::filter($request);

        $withPast = $request->boolean('past');
        $today = Carbon::today();

        // 候補をざっくり引く（ARENAはJSONの中を見ないと分からないのでSQLでは落とさない）。
        $projects = BroadcastKind::candidates(
            OfficeScope::applyToProjects(Project::query(), $office)
        )
            ->orderBy('start_date')
            ->get()
            // ここが本当のふるい。⚠ 判定は BroadcastKind の1か所だけ。
            ->filter(fn (Project $p) => BroadcastKind::isOn($p))
            ->values();

        $contentNames = Content::pluck('content_name', 'id');

        // 社員の名前（担当の表示用）。⚠ 全拠点ぶん（2026-09-18 baba「全拠点の社員が表示されるように」）。
        $employeeNames = Person::employees()->pluck('name', 'id');

        $rows = $projects
            ->map(function (Project $p) use ($contentNames, $employeeNames) {
                $ownerId = (string) ($p->broadcast_owner_id ?? '');

                return [
                    'id' => $p->id,
                    'day' => $p->start_date?->format('Y-m-d'),
                    'dayLabel' => $p->start_date ? $p->start_date->format('n/j') : '日付未定',
                    // ⚠ 曜日は「（土）」の形まで作って渡す。画面で「文字のすぐ後ろに @if」を書くと
                    //   Blade が命令と見なさず、そのまま画面に出て JS が死ぬ（過去に踏んでいる）。
                    'dowLabel' => $p->start_date
                        ? '（'.['日', '月', '火', '水', '木', '金', '土'][(int) $p->start_date->dayOfWeek].'）'
                        : '',
                    'content' => ProjectContentName::of($p, $contentNames),
                    'client' => (string) ($p->client ?? ''),
                    'kind' => BroadcastKind::label($p),
                    'isArena' => BroadcastKind::isArena($p),
                    'tool' => (string) ($p->online_tool ?? ''),
                    'place' => (string) ($p->location ?? ''),
                    'meet' => (string) ($p->start_time ?? ''),
                    'leave' => (string) ($p->end_time ?? ''),
                    'evStart' => (string) ($p->event_start_time ?? ''),
                    'evEnd' => (string) ($p->event_end_time ?? ''),
                    'office' => (string) ($p->office ?? ''),
                    'director' => (string) ($employeeNames[(string) ($p->director_id ?? '')] ?? ''),
                    'ownerId' => $ownerId,
                    'ownerName' => (string) ($employeeNames[$ownerId] ?? ''),
                    'outside' => (string) ($p->broadcast_owner_name ?? ''),
                    'cancelled' => (bool) $p->is_cancelled,
                    'draft' => $p->status === '下書き',
                ];
            })
            // 期間で絞る。⚠ 開催日が未定の案件は落とさない（配信の手配が要るのに消えると気づけない）。
            ->filter(function (array $r) use ($withPast, $today) {
                if ($withPast || $r['day'] === null) {
                    return true;
                }

                return $r['day'] >= $today->format('Y-m-d')
                    && $r['day'] <= $today->copy()->addDays(self::DEFAULT_DAYS)->format('Y-m-d');
            })
            ->values();

        // 担当がまだ決まっていない案件の数（この画面でいちばん見たい数字）。
        $live = $rows->reject(fn (array $r) => $r['cancelled']);
        $noOwner = $live->filter(fn (array $r) => $r['ownerId'] === '' && $r['outside'] === '');

        // 期間の切替リンク。⚠ 拠点（?office=）を必ず持ち回る
        //   （落とすと「全拠点で見ていたのに、期間を変えただけで自分の拠点に戻る」）。
        $keep = [];
        if ($request->filled('office')) {
            $keep['office'] = (string) $request->query('office');
        }
        $qs = fn (array $extra = []) => ($p = http_build_query($keep + $extra)) ? '?'.$p : '';

        return view('broadcast_list', [
            'rows' => $rows,
            'sumRows' => $live->count(),
            'sumNoOwner' => $noOwner->count(),
            'officeScope' => $office,
            'officeParam' => $request->filled('office') ? (string) $request->query('office') : '',
            'withPast' => $withPast,
            'days' => self::DEFAULT_DAYS,
            'urlFuture' => '/broadcast-list'.$qs(),
            'urlPast' => '/broadcast-list'.$qs(['past' => 1]),
            // 担当のプルダウン。⚠ 全拠点の社員（拠点で絞らない）。
            'employees' => Person::employees()
                ->orderBy('name')
                ->get(['id', 'name', 'office'])
                ->map(fn (Person $e) => [
                    'id' => $e->id,
                    // プルダウンに出す文字。⚠ ここまで作って渡す（画面で @if をつなげない）。
                    'label' => $e->name.(($e->office ?? '') !== '' ? '（'.$e->office.'）' : ''),
                ])
                ->values(),
            // 直せるか＝社員以上（スタッフはこの画面を使わない）。
            // ⚠ 案件ごとの拠点チェックは保存の入口（POST /projects/cells）で通る。
            'canEdit' => (bool) Auth::user() && (Auth::user()->role ?? '') !== 'staff',
        ]);
    }
}
