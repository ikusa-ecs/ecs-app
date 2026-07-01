<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * スタッフ公開ボード（/assign-publish）。
 *
 * これまで画面は public/ecs/data/cases.js（仮データ）を読み、公開ON/OFFは
 * ブラウザの localStorage に保存していた（＝閉じると消える・他PCで共有されない）。
 * ここでは DB の projects テーブルを読み、公開状態 staff_published を「背骨」として
 * 表示し、ボタン操作で DB に保存する。表示JS（表の組み立て）はそのまま流用する。
 *
 * ※ G-2 が触る ProjectController とは別物。ここからは projects の staff_published 列
 *   だけを更新し、他の列は触らない。
 */
class AssignPublishController extends Controller
{
    /** 公開ボードを表示（DBの案件＋公開状態を渡す）。 */
    public function index()
    {
        $today = Carbon::today();

        $cases = Project::orderBy('start_date')->get()->map(function (Project $p) use ($today) {
            // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。画面が日付・月分けに使う。
            $off = $p->start_date
                ? intdiv($p->start_date->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                : 0;

            // added ＝ 今日から登録日まで何日（マイナス＝過去に登録）。「登録〇/〇」表示用。
            $added = $p->created_at
                ? intdiv($p->created_at->copy()->startOfDay()->timestamp - $today->timestamp, 86400)
                : 0;

            return [
                'id'        => $p->id,
                'name'      => $p->project_name,
                'client'    => $p->client ?? '',
                'cat'       => $p->site_category ?? '通常',   // 現場種別（バッジ用）
                'category'  => $p->category ?? '',            // 案件区分（通常案件/追加案件）
                'need'      => $p->required_count ?? '—',
                'off'       => $off,
                'added'     => $added,
                'meet'      => $p->start_time ?? '—',          // 社員の集合時間
                'leave'     => $p->end_time ?? '—',            // 社員の解散時間
                'staffMeet' => $p->staff_meet_time,            // スタッフ向け集合（未設定=null→社員と同じ）
                'staffLeave' => $p->staff_leave_time,          // スタッフ向け解散（未設定=null→社員と同じ）
                'place'     => $p->location ?? '',
                'meetPlace' => $p->assembly_type ?? '',
                'published' => (bool) $p->staff_published,      // 公開状態（DBの背骨）
            ];
        })->values();

        return view('assign_publish', [
            'cases'  => $cases,
            'notice' => Setting::get('staff_notice', ''),   // スタッフ画面のお知らせ文（DB保存）
        ]);
    }

    /**
     * 公開ON/OFF を DB に保存する（1件でも複数まとめてでも同じ口）。
     * 受け取り：ids（案件IDの配列）／publish（true=公開・false=非公開）。
     */
    public function setPublish(Request $request)
    {
        $data = $request->validate([
            'ids'     => ['required', 'array', 'min:1'],
            'ids.*'   => ['string'],
            'publish' => ['required', 'boolean'],
        ]);

        $updated = Project::whereIn('id', $data['ids'])
            ->update(['staff_published' => $data['publish']]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    /**
     * スタッフ向けの集合・解散時間を DB に保存する。
     * 受け取り：id（案件ID）＋ staff_meet または staff_leave のどちらか／両方。
     * 空文字は「未設定」に戻す（null）＝社員の時間をそのまま使う扱いに戻る。
     */
    public function setTime(Request $request)
    {
        $data = $request->validate([
            'id'          => ['required', 'string'],
            'staff_meet'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'staff_leave' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $project = Project::findOrFail($data['id']);
        if ($request->has('staff_meet')) {
            $project->staff_meet_time = trim((string) $request->input('staff_meet')) ?: null;
        }
        if ($request->has('staff_leave')) {
            $project->staff_leave_time = trim((string) $request->input('staff_leave')) ?: null;
        }
        $project->save();

        return response()->json(['ok' => true]);
    }

    /**
     * スタッフ画面のお知らせ文を DB に保存する（空＝既定文に戻す）。
     */
    public function setNotice(Request $request)
    {
        $data = $request->validate([
            'notice' => ['nullable', 'string', 'max:2000'],
        ]);

        Setting::put('staff_notice', trim((string) ($data['notice'] ?? '')));

        return response()->json(['ok' => true]);
    }
}
