<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * エントリー新着（来た順）の**古いURL**（/entry-feed）。
 *
 * 2026-08-21 に独立した画面として作ったが、2026-09-10 に
 * **エントリー一覧（/entries）の4つ目のタブへ引っ越した**（サイドメニューが長くなったため・baba要望）。
 *
 * この入口は残してある＝ブックマークや、前に配った案内から開いた人が迷子にならないように、
 * 同じ絞り込み（期間・追加案件のみ・新人のみ・拠点）を持ったまま新しい場所へ転送する。
 * ⚠ 中身の作り方の正本は `App\Support\EntryFeed`、見た目は `partials/entry_feed_panel.blade.php`。
 */
class EntryFeedController extends Controller
{
    public function index(Request $request)
    {
        // いま付いている絞り込みをそのまま持っていく（付いていないものは付けない）。
        $query = array_filter([
            'view'   => 'feed',
            'days'   => $request->query('days'),
            'extra'  => $request->query('extra'),
            'new'    => $request->query('new'),
            'office' => $request->query('office'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect('/entries?' . http_build_query($query));
    }
}
