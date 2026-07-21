<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\Content;
use App\Models\Person;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * 「ログイン中の社員（＝自分）」まわりの共通データ組み立て。
 *
 * マイページ（/mypage）と収支入力（/mypage-finance）が同じ定義で
 *   ・自分＝誰か
 *   ・全案件（cases.js と同じ形）
 *   ・自分のアサイン（案件ID → 役割コード）
 * を使えるように、ここに一本化する（データの二重管理を避けるため）。
 *
 * 「自分」＝ログイン中の本人。認証（ログイン）が入ったので Auth::user() を使う。
 * 未ログイン時（テスト等）のみ、従来どおり社員 E-007 / baba にフォールバックする。
 */
class PersonalCases
{
    /** ログイン中の本人モデル。未ログイン時のみ E-007／baba にフォールバック。無ければ null。 */
    public static function meModel(): ?Person
    {
        // ログイン中なら必ずその本人（誰でログインしても自分のデータが出るように）。
        $user = Auth::user();
        if ($user instanceof Person) {
            return $user;
        }

        // 未ログイン時のフォールバック（従来の見本表示・テスト向け）。
        return Person::where('role', 'employee')
            ->where(fn ($q) => $q->where('id', 'E-007')->orWhere('name', 'baba'))
            ->first();
    }

    /** 画面表示用の「自分」の情報（name/email/dept）。 */
    public static function meInfo(?Person $me = null): array
    {
        $me = $me ?: self::meModel();

        return [
            'id' => $me->id ?? null,
            'name' => $me->name ?? 'baba',
            'email' => $me->email ?? 'baba@ikusa.co.jp',
            'dept' => $me->department ?? 'イベプラ',
        ];
    }

    /** 全案件を cases.js と同じ形に詰め替えて返す。 */
    public static function cases(Carbon $today): Collection
    {
        $contentNames = Content::pluck('content_name', 'id');

        return Project::with(['director:id,name'])
            ->orderBy('start_date')
            ->get()
            ->map(fn (Project $p) => self::toCase($p, $today, $contentNames))
            ->values();
    }

    /** 自分のアサイン（案件ID → 役割コード。キャンセル除く）。 */
    public static function myAssign(?Person $me): array
    {
        if (! $me) {
            return [];
        }

        return Assignment::where('staff_id', $me->id)
            ->where('status', '!=', 'キャンセル')
            ->get()
            ->mapWithKeys(fn (Assignment $a) => [$a->project_id => $a->role ?: '現場'])
            ->all();
    }

    /** Project 1件 → cases.js と同じ形（マイページ／収支が使う項目）。 */
    private static function toCase(Project $p, Carbon $today, $contentNames): array
    {
        // off ＝ 今日から開催日まで何日後か（マイナス＝過去）。
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

        // 実施形態（収支の「当日スタッフ費」どの行に人数を仮入力するか）。
        $format = (string) ($p->format ?? '');
        $fmt = 'real';
        if (mb_strpos($format, 'オンライン') !== false) {
            $fmt = 'online';
        } elseif (mb_strpos($format, 'ロング') !== false) {
            $fmt = 'long';
        }

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
            'sales' => is_array($p->sales_owners) ? ($p->sales_owners[0] ?? '—') : '—',
            'status' => $p->status ?? '未着手',
            'note' => $p->note ?? '',
            'need' => $p->required_count ?? '',
            'fmt' => $fmt,
            // リストのバッジ用（大型・前泊・予備日・リハ）
            'scale' => $p->scale ?? '',
            'lodging' => $p->lodging ?? '',
            'dateType' => $p->date_type ?? '本番',
            'archived' => $off < 0,
            'draft' => $p->status === '下書き',
            'off' => $off,
        ];
    }
}
