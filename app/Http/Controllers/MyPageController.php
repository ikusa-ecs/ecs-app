<?php

namespace App\Http\Controllers;

use App\Models\GoogleCalendarLink;
use App\Support\GoogleCalendarSync;
use App\Support\OfficeScope;
use App\Support\PersonalCases;
use App\Support\SfInboxOwner;
use App\Support\SfOffice;
use App\Support\SfOpportunityMap;
use Illuminate\Http\Request;
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

        // 「自分」まわりの共通部品（収支入力 /mypage-finance と同じ定義）。
        $me = PersonalCases::meModel();

        // 通知設定（自分の people.notify_settings）。未設定なら全オフを既定にする。
        $saved = ($me && is_array($me->notify_settings)) ? $me->notify_settings : [];
        $notify = [
            'follow'   => (bool) ($saved['follow'] ?? false),
            'assign'   => (bool) ($saved['assign'] ?? false),
            'deadline' => (bool) ($saved['deadline'] ?? false),
        ];

        $sfOffices = OfficeScope::options() ?: SfOffice::ECS_OFFICES;

        return view('mypage', [
            'me' => PersonalCases::meInfo($me),
            'cases' => PersonalCases::cases($today, $me),
            'myAssign' => PersonalCases::myAssign($me),
            'notify' => $notify,
            // Googleカレンダーとの連携（2026-11-02）。configured＝管理者が鍵を入れたか／linked＝本人が連携したか。
            'googleCal' => [
                'configured' => GoogleCalendarSync::configured(),
                'linked' => $me ? GoogleCalendarLink::whereKey($me->id)->exists() : false,
                'email' => $me ? (string) (GoogleCalendarLink::find($me->id)->google_email ?? '') : '',
                'syncedAt' => $me ? optional(GoogleCalendarLink::find($me->id)?->synced_at)->format('n/j H:i') : null,
            ],
            // 自分が担当営業の、SFから届いた「取り込み待ち」の商談（2026-11-20）。本人が見て登録／見送りを押す。
            'sfMine' => $this->sfMine($me),
            // 本人が見送った商談（マイページから戻せるように・2026-11-20 小沼さん）。
            'sfSkipped' => $me ? SfInboxOwner::skippedFor($me)->map(fn ($item) => [
                'item' => $item,
                'name' => (string) (SfOpportunityMap::get($item->payload ?? [], SfOpportunityMap::F_NAME) ?? '（商談名なし）'),
                'date' => SfOpportunityMap::get($item->payload ?? [], SfOpportunityMap::F_DATE),
            ])->all() : [],
            'sfOffices' => $sfOffices,
            // 登録フォームの拠点のはじめの値＝押す本人の拠点（2026-11-20 小沼さん）。SFの担当拠点は表に参考として出すだけ。
            // 本人の拠点が選択肢に無い／空なら null＝「選んでください」＋必須（推測で東京などに入れない）。
            'sfMyOffice' => ($me && in_array(trim((string) $me->office), $sfOffices, true)) ? trim((string) $me->office) : null,
        ]);
    }

    /**
     * 自分あての取り込み待ちの商談と、その下見（受信箱と同じ SfOpportunityMap::preview）。
     * ⚠ どのヨミ（確度）も自動では登録しない＝ここは並べるだけ。登録は本人が押したときだけ。
     * ⚠ 1件の中身が壊れていても、マイページ全体は止めない。
     */
    private function sfMine($me): array
    {
        if (! $me) {
            return [];
        }

        return SfInboxOwner::waitingFor($me)->map(function ($item) {
            try {
                $p = SfOpportunityMap::preview($item->payload ?? []);
            } catch (\Throwable $e) {
                $p = null;
            }

            return ['item' => $item, 'p' => $p];
        })->all();
    }

    /**
     * 通知設定の保存（POST /mypage/notify）。
     *
     * マイページの通知オン・オフ（フォロー所感／アサイン確定／締切間近）を
     * 自分（people）の notify_settings に保存する。
     * 認証はMTG後のため「自分」は PersonalCases::meModel() 固定（E-007/baba）。
     * 自分が見つからない場合は保存だけスキップして正常応答を返す。
     */
    public function saveNotify(Request $request)
    {
        $data = $request->validate([
            'follow'   => 'required|boolean',
            'assign'   => 'required|boolean',
            'deadline' => 'required|boolean',
        ]);

        $me = PersonalCases::meModel();
        if (! $me) {
            return response()->json(['ok' => true, 'saved' => false]);
        }

        $me->notify_settings = [
            'follow'   => (bool) $data['follow'],
            'assign'   => (bool) $data['assign'],
            'deadline' => (bool) $data['deadline'],
        ];
        $me->save();

        return response()->json(['ok' => true]);
    }
}
