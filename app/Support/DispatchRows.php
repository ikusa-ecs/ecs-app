<?php

namespace App\Support;

use App\Models\ProjectDispatch;
use Illuminate\Support\Collection;

/**
 * 案件ごとの「派遣依頼」を、画面に出す形にそろえて渡す正本（2026-09-16 baba要望）。
 *
 * ⚠ なぜ1か所にまとめるか＝派遣を出す画面が4つある
 *   （日別ボード／アサイン表／案件別アサイン／ピックアップ）。
 *   画面ごとに読み方と見せ方を書くと「この画面だけ状況が出ない」が必ず起きる。
 *   実際 2026-09-16 まで、派遣を読んでいたのは日別ボードの1画面だけだった。
 *
 * ⚠ キャンセルも消さずに渡す（頼んだ事実が消えると経緯が追えない）。
 *   画面では薄く取り消し線で出し、人数には数えない（→ liveCount）。
 *
 * ⚠ 状態の言葉と色の種類は App\Support\DispatchStatus が正本。ここでは持たない。
 */
final class DispatchRows
{
    /**
     * まとめて読む。返り値＝案件ID => 行の配列。
     * ⚠ 案件の数だけ問い合わせない（一覧画面は案件が数十件ある）。
     */
    public static function forProjects(iterable $projectIds): array
    {
        $ids = collect($projectIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return ProjectDispatch::whereIn('project_id', $ids->all())
            ->orderBy('id')
            ->get()
            ->groupBy('project_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($d) => self::row($d))->values()->all())
            ->all();
    }

    /** 1案件ぶん（案件別アサインのように1件だけ見る画面から使う）。 */
    public static function forProject(?string $projectId): array
    {
        if (! $projectId) {
            return [];
        }

        return self::forProjects([$projectId])[$projectId] ?? [];
    }

    /** 頼んでいる人数＝キャンセル以外の合計（「あと何名」の計算に使う）。 */
    public static function liveCount(array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            if (empty($r['cancelled'])) {
                $n += (int) ($r['count'] ?? 0);
            }
        }

        return $n;
    }

    /** 1行を画面用にそろえる。ここで作った形を、4画面すべてがそのまま使う。 */
    private static function row(ProjectDispatch $d): array
    {
        $agency = (string) $d->agency;
        $count = (int) $d->count;
        $role = trim((string) ($d->role ?? ''));
        $status = (string) $d->status;
        $note = trim((string) ($d->note ?? ''));

        return [
            'id' => $d->id,
            'agency' => $agency,
            'count' => $count,
            'role' => $role,
            'status' => $status,
            'cls' => DispatchStatus::cls($status),
            'cancelled' => $status === DispatchStatus::CANCELLED,
            'note' => $note,
            // マウスを乗せたときに出す説明。⚠ 直す・消す入口は派遣一覧の1か所だけ
            //   （どの画面からでも消せると、押し間違いで記録が消える）。
            'tip' => $agency.'（'.$count.'名'.($role !== '' ? '・'.$role : '').'・'.$status.'）'
                .($note !== '' ? '／'.$note : '')
                .'　※直す・消すのは「派遣一覧」から',
        ];
    }
}
