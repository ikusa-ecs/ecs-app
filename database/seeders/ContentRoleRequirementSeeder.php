<?php

namespace Database\Seeders;

use App\Models\ContentRoleRequirement;
use App\Support\AssignmentRole;
use Database\Seeders\Concerns\DemoOnly;
use Illuminate\Database\Seeder;

/**
 * コンテンツ別・規模別の必要ポジション人数の【仮の見本】。
 *
 * ⚠ここの人数はデモ用の“それっぽい仮値”です。現場の正しい編成ではありません。
 *   正式な人数は、コンテンツマスタの「必要人数」画面（/masters/contents/{id}/requirements）で
 *   差し替えてください。差し替えた値は content_role_requirements に上書き保存されます。
 *
 * この見本があると、アサイン画面（/project-assign）に「ポジション枠（D×1・MC×2…）」が表示され、
 * migrate:fresh --seed でDBを作り直しても枠が復活します（デモが毎回同じ状態になる）。
 *
 * ※開発・動作確認用のダミー。本番には入れない。
 */
class ContentRoleRequirementSeeder extends Seeder
{
    use DemoOnly;

    /** 規模ごとの標準編成（仮）。役割コードは AssignmentRole の正本に合わせる。 */
    private const TEMPLATES = [
        '大型' => ['D' => 1, 'OP' => 1, 'MC' => 2, 'FC' => 3, 'CK' => 1, 'SP' => 1, 'RP' => 2],
        '中型' => ['D' => 1, 'OP' => 1, 'MC' => 1, 'FC' => 2, 'CK' => 1, 'RP' => 1],
        '小型' => ['D' => 1, 'MC' => 1, 'FC' => 1, 'RP' => 1],
    ];

    /** メインのコンテンツ（案件が実際に使っている規模だけ標準編成を入れる）。 */
    private const MAIN = [
        'CT-001' => ['中型', '大型'],
        'CT-002' => ['大型', '小型'],
        'CT-003' => ['中型'],
        'CT-004' => ['中型'],
        'CT-005' => ['大型'],
        'CT-006' => ['中型'],
        'CT-102' => ['中型'],
    ];

    /** 複数コンテンツ案件で「合計される」様子を見せるための追加コンテンツ（小さめの編成）。 */
    private const ADDON = [
        'CT-100' => ['小型' => ['MC' => 1, 'RP' => 1], '大型' => ['MC' => 1, 'RP' => 1]],
        'CT-101' => ['大型' => ['SP' => 1, 'CK' => 1]],
    ];

    public function run(): void
    {
        // 本番など、見本データを入れてはいけない環境では何もしない（安全装置）。
        if ($this->demoBlocked()) {
            return;
        }

        // メイン：規模ごとの標準編成
        foreach (self::MAIN as $contentId => $scales) {
            foreach ($scales as $scale) {
                $this->upsertMany($contentId, $scale, self::TEMPLATES[$scale]);
            }
        }

        // 追加：複数コンテンツ案件の合計デモ用
        foreach (self::ADDON as $contentId => $byScale) {
            foreach ($byScale as $scale => $roles) {
                $this->upsertMany($contentId, $scale, $roles);
            }
        }
    }

    /** コンテンツ×規模×ポジションの人数を投入（updateOrCreate＝重複防止）。 */
    private function upsertMany(string $contentId, string $scale, array $roles): void
    {
        foreach ($roles as $position => $count) {
            if (! AssignmentRole::isValid($position)) {
                continue; // 表記ゆれ・未知コードは入れない（正本 AssignmentRole に寄せる）
            }
            ContentRoleRequirement::updateOrCreate(
                ['content_id' => $contentId, 'scale' => $scale, 'position' => $position],
                ['count' => $count]
            );
        }
    }
}
