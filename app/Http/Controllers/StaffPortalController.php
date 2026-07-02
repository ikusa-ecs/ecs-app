<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * スタッフ側ポータル（/staff-portal）。
 *
 * 「確定アサイン」タブと、稼働希望カレンダーの「イベント（確定）」表示を
 * DB の projects テーブルから作る。これまではブラウザの localStorage
 * （ecs_publish_<id>）を見ていたため、公開の状態が同じブラウザの中でしか
 * 共有されなかった。ここでは公開の「背骨」である staff_published 列を読み、
 * 担当が公開ボード（/assign-publish）で公開ONにした案件だけをスタッフに見せる。
 *
 * ※ 募集中タブ（エントリー）は応募フロー側の話なので、ここでは触らない
 *   （画面側は従来どおり cases.js を読む）。ここで渡すのは「公開済み案件」だけ。
 */
class StaffPortalController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // 公開ON の案件だけを開催日の近い順に取り出す。
        $published = Project::where('staff_published', true)
            ->orderBy('start_date')
            ->get()
            ->map(function (Project $p) use ($today) {
                // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。画面が日付計算に使う。
                $off = $p->start_date
                    ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                    : 0;

                return [
                    'id'        => $p->id,
                    'content'   => $p->project_name,
                    'client'    => $p->client ?? '',
                    'place'     => $p->location ?? '',
                    'meetPlace' => $p->assembly_type ?? '',
                    // スタッフ向けの集合・解散時間。担当が公開ボードで直していれば優先、無ければ社員の時間。
                    'meet'      => $p->staff_meet_time ?? $p->start_time ?? '—',
                    'leave'     => $p->staff_leave_time ?? $p->end_time ?? '—',
                    'off'       => $off,
                ];
            })
            ->values();

        return view('staff_portal', [
            'published' => $published,
            'recruitJobs' => $this->recruitJobs($today),
            'notice' => Setting::get('staff_notice', ''),   // スタッフ画面のお知らせ文（DB保存）
        ]);
    }

    /**
     * 募集中タブに出す案件リスト（projects から）。
     * 「募集する・完了/下書きでない」案件を、画面の jobs 変換がそのまま読める項目名で返す。
     * ※「あなたがエントリー中か」は本人特定（ログイン）が要るため、画面側の従来モックのまま
     *   （ここでは案件リストだけを本物にする。状態は満員→締切／それ以外→募集中）。
     */
    private function recruitJobs(Carbon $today)
    {
        $projects = Project::where('is_recruiting', true)
            ->whereNotIn('status', ['完了', '下書き'])
            ->orderBy('start_date')
            ->get();

        if ($projects->isEmpty()) {
            return collect();
        }

        // 充足数＝確定/仮アサイン（キャンセル除く）の人数。案件ごとにまとめて数える。
        $filledByProject = Assignment::whereIn('project_id', $projects->pluck('id'))
            ->where('status', '!=', 'キャンセル')
            ->get(['project_id', 'staff_id'])
            ->groupBy('project_id')
            ->map(fn ($rows) => $rows->pluck('staff_id')->unique()->count());

        $contentNames = Content::pluck('content_name', 'id');

        // 通常案件の締切＝全体で1つの「一斉締切日」（settings）。追加案件は下の deadlineLabel で個別計算。
        $bulkDeadline = trim((string) Setting::get('entry_deadline', ''));

        return $projects->map(function (Project $p) use ($today, $filledByProject, $contentNames, $bulkDeadline) {
            $off = $p->start_date
                ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->copy()->startOfDay()->timestamp, 86400)
                : 0;

            $firstContentId = is_array($p->content_ids) ? ($p->content_ids[0] ?? null) : null;
            $content = $firstContentId ? ($contentNames[$firstContentId] ?? $p->project_name) : $p->project_name;

            $format = (string) ($p->format ?? '');
            $fmt = '';
            if (mb_strpos($format, 'オンライン') !== false) {
                $fmt = 'online';
            } elseif (mb_strpos($format, 'ロング') !== false) {
                $fmt = 'long';
            } elseif (mb_strpos($format, 'リアル') !== false) {
                $fmt = 'real';
            }

            // 画面の jobs 変換（staff_portal.blade）がそのまま読める項目名で返す。
            return [
                'id' => $p->id,
                'content' => $content,
                'client' => $p->client ?? '',
                'place' => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'area' => $p->operation_place ?? '',
                'fmt' => $fmt,
                'scale' => $p->scale ?? '',
                'repeat' => (bool) $p->is_repeat,
                'lodging' => $p->lodging ?? '無',
                'dayType' => $p->date_type ?? '本番',
                'parentId' => $p->parent_project_id,
                'off' => $off,
                'need' => $p->required_count ?? 0,
                'filled' => $filledByProject->get($p->id, 0),
                'meet' => $p->start_time ?? '—',
                'leave' => $p->end_time ?? '—',
                'enter' => $p->event_enter_time ?? '—',
                'evStart' => $p->event_start_time ?? '—',
                'evEnd' => $p->event_end_time ?? '—',
                'category' => $p->category ?? '通常案件',
                'deadline' => $this->deadlineLabel($p, $bulkDeadline),
                'recruit' => true,
                'archived' => $off < 0,   // 過去のイベントは募集タブに出さない
                'draft' => false,
            ];
        })->values();
    }

    /**
     * スタッフ画面に出す「締切」の表示ラベル（例 "7/5"）。締切は表示だけ＝過ぎても応募は受け付ける。
     *  - 通常案件＝全体で1つの一斉締切日（未設定なら空文字＝表示しない）。
     *  - 追加案件＝公開した日（extra_published_at・無ければ登録日）＋3日。その日が土日なら月曜にずらす。
     */
    private function deadlineLabel(Project $p, string $bulkDeadline): string
    {
        if (($p->category ?? '通常案件') === '追加案件') {
            $base = $p->extra_published_at ?? $p->created_at;
            if (!$base) {
                return '';
            }
            $d = Carbon::parse($base)->startOfDay()->addDays(3);
            if ($d->isSaturday()) {
                $d->addDays(2);   // 土曜 → 月曜
            } elseif ($d->isSunday()) {
                $d->addDay();     // 日曜 → 月曜
            }

            return $d->format('n/j');
        }

        // 通常案件＝一斉締切日（未設定なら空＝チップを出さない）。
        if ($bulkDeadline === '') {
            return '';
        }
        try {
            return Carbon::parse($bulkDeadline)->format('n/j');
        } catch (\Exception $e) {
            return '';
        }
    }
}
