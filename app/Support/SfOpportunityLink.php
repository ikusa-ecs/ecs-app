<?php

namespace App\Support;

use App\Models\Project;
use App\Models\SfInboxItem;

/**
 * SFから登録した案件 → SFの商談の画面へのリンク（2026-11-20）。
 *
 * 【決まり（小沼さん 11/20）】
 *   ・ECSの案件番号（P-西暦-連番）はそのまま。SFの商談Idは受信箱（sf_inbox_items.project_id）からたどる。
 *   ・URL＝ config('services.salesforce.url') の末尾に「/商談Id」を付けたもの。
 *   ・config が空ならリンクを出さない（つなぎ先が決まっていないのに、壊れたリンクを出さないため）。
 *     ただし「この案件はSalesforceの商談『…』から登録しました」の一文は出す（forProjectInfo の name）。
 *   ・複数日の商談から作った連日イベントは、受信箱には初日（親）しか入っていない。
 *     2日目からの案件は親（parent_project_id）をたどって同じ商談を出す。
 */
final class SfOpportunityLink
{
    /** 案件IDから、SFの商談を開くURL。SFから登録した案件でなければ null。 */
    public static function forProject(?string $projectId): ?string
    {
        return self::forProjectInfo($projectId)['url'] ?? null;
    }

    /**
     * 案件IDから、SFの商談の名前とURL。SFから登録した案件でなければ null。
     *
     * @return array{name: ?string, url: ?string}|null  url は config が空・Idが変なときは null
     */
    public static function forProjectInfo(?string $projectId): ?array
    {
        $projectId = trim((string) $projectId);
        if ($projectId === '') {
            return null;
        }

        $item = SfInboxItem::where('project_id', $projectId)->first();
        if (! $item) {
            $parentId = Project::whereKey($projectId)->value('parent_project_id');
            $item = $parentId ? SfInboxItem::where('project_id', $parentId)->first() : null;
        }
        if (! $item) {
            return null;
        }

        $payload = is_array($item->payload) ? $item->payload : [];
        $name = trim((string) ($payload['Name'] ?? '')) ?: null;

        $url = null;
        $base = rtrim(trim((string) config('services.salesforce.url', '')), '/');
        $oppId = $item->sf_opportunity_id;
        // ⚠ SFのIdは英数字15か18文字。それ以外の文字が入っていたらURLに混ぜない（変な文字でリンク先が変わらないように）。
        if ($base !== '' && is_string($oppId) && preg_match('/\A[A-Za-z0-9]{15,18}\z/', $oppId)) {
            $url = $base.'/'.$oppId;
        }

        return ['name' => $name, 'url' => $url];
    }
}
