<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 編集履歴の「どこから変えたか」を作る係（2026-10-13 baba「どのページのどの部分か分かるようにしてほしい」）。
 *
 * 作り方＝「ページ › 部分」。
 *   ・ページ＝そのとき開いていた画面（送信元＝Referer）。同じ保存の入口を何画面からも使うので、
 *     送り先だけでは「エントリー一覧から入れたのか日別ボードからか」が分からないため。
 *   ・部分＝保存の送り先（POSTのURL）。
 * どちらも日本語の名前に置き換える。名前の無いURLは、そのまま出す（黙って捨てない）。
 * ⚠ 画面や保存の入口を足したら、ここに1行足す（足さなくても動くが、URLのまま出る）。
 */
final class HistorySource
{
    /** 画面（左メニューの表記に合わせる）。 */
    private const PAGES = [
        'dashboard' => 'ダッシュボード',
        'projects' => '案件一覧',
        'past-projects' => '過去案件',
        'assign-sheet' => 'アサイン表',
        'project-form' => '案件登録',
        'assign-publish' => 'スタッフ公開ボード',
        'assign-dashboard' => 'アサインダッシュボード',
        'assign' => '日別ボード',
        'assign-detail' => '案件別アサイン',
        'project-assign' => '案件別アサイン',
        'pickup' => 'ピックアップ',
        'assign-director' => 'D決め',
        'entries' => 'エントリー一覧',
        'auto-assign-month' => '月まとめ自動アサイン',
        'rookies' => '新人',
        'dispatch-list' => '派遣一覧',
        'broadcast-list' => '配信・中継案件一覧',
        'paper-stock' => '謎解きの紙 在庫',
        'imports' => 'CSV一括取込',
        'project-import' => 'CSV一括取込（案件）',
        'past-import' => 'アサイン表の取込',
        'sheet-inbox' => 'アサイン表の受信箱',
        'masters' => 'マスタ管理',
        'masters/content-cleanup' => 'マスタ管理（台帳の片づけ）',
        'masters/content-reorg' => 'マスタ管理（台帳の整理）',
        'settings' => '共通設定',
        'mypage' => 'マイページ',
        'mypage-finance' => '収支入力',
        'finance-list' => '収支一覧',
    ];

    /** 保存の送り先（* は何でも）。上から順に見る。 */
    private const PARTS = [
        'project-form' => '案件の保存',
        'project-form/paste' => 'アサイン表から貼り付け',
        'projects/catering' => 'ケータリング',
        'projects/cells' => '表のマスを直接編集',
        'projects/archive' => 'アーカイブ',
        'projects/cancel' => 'キャンセル',
        'projects/origin-helps' => '登録拠点のボードにも出す',
        'projects/bulk-delete' => 'まとめて削除',
        'projects/*/delete' => '削除',
        'projects/confirm-members' => 'メンバーを確定',
        'project-import' => '案件CSVの取込',
        'past-import' => '取込',
        'sheet-sync' => '毎朝の自動受信',
        'auto-assign-month/run' => '自動アサインを実行',
        'auto-assign-month/undo' => '自動アサインを元に戻す',
        'assign-sheet/project' => '案件の時間・人数・備考',
        'assign-sheet/slot' => '人の枠',
        'assign-sheet/director-slot' => 'Dの枠を足す',
        'assign-sheet/share' => '他拠点へのヘルプ・巻き取り',
        'assign-sheet/share/remove' => 'ヘルプ・巻き取りを外す',
        'assign-director/save' => 'D・SD・FC',
        'project-assign/save' => 'アサインの保存',
        'assign-publish/set' => '公開のオン・オフ',
        'assign-publish/time' => '時間',
        'assign-publish/notice' => 'スタッフへのお知らせ',
        'assign-publish/memo' => 'メモ',
        'assign-publish/count' => '必要人数（運営人数）',
        'assign-publish/office-counts' => '拠点ごとの必要人数',
        'assign-publish/staff-info' => 'スタッフ向けの情報',
        'assign-publish/category' => '区分',
        'assign-publish/category-bulk' => '区分（まとめて）',
        'assign-publish/deadline' => '募集の締切',
        'entries/assign' => 'アサインを入れる・外す',
        'assignments/confirm-month' => '月まとめで確定',
        'pickup/save' => 'ピックアップの保存',
        'dispatches' => '派遣',
        'dispatches/*' => '派遣',
        'mypage-finance/save' => '収支の保存',
        'masters/content-cleanup' => '台帳の片づけ',
        'masters/content-reorg' => '台帳の整理',
        'masters/contents/*/oneoff' => 'コンテンツを単発に',
        'masters/contents/*/delete' => 'コンテンツの削除',
        'masters/contents/bulk-delete' => 'コンテンツのまとめて削除',
    ];

    /** いまの操作の「ページ › 部分」。画面からの操作でなければ「システム（自動の処理）」。 */
    public static function current(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'システム（自動の処理）';
        }
        $req = request();
        if (! $req) {
            return 'システム（自動の処理）';
        }

        $path = trim($req->path(), '/');
        $part = self::match(self::PARTS, $path);

        // 開いていた画面（送信元）。同じサイトの中のときだけ使う。
        $page = null;
        $ref = (string) $req->headers->get('referer', '');
        if ($ref !== '') {
            $host = parse_url($ref, PHP_URL_HOST);
            if ($host === null || $host === $req->getHost()) {
                $page = self::match(self::PAGES, trim((string) parse_url($ref, PHP_URL_PATH), '/'));
            }
        }
        // 送信元が分からないとき（毎朝の自動受信など）は、送り先の名前を画面の名前にも使う。
        $page ??= self::PAGES[$path] ?? null;

        if ($page !== null && $part !== null) {
            return $page === $part ? $page : $page.' › '.$part;
        }

        return $page ?? $part ?? ('/'.$path);
    }

    private static function match(array $map, string $path): ?string
    {
        if (isset($map[$path])) {
            return $map[$path];
        }
        foreach ($map as $pattern => $label) {
            if (str_contains($pattern, '*') && Str::is($pattern, $path)) {
                return $label;
            }
        }

        return null;
    }
}
