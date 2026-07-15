<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * 必要アサイン人数リスト（IKUSA公式）を content_role_requirements へ投入する初期データ。
 *
 * 正本CSVは共有ドライブ（稼働管理\ECS\必要アサイン人数.csv）だが、どの環境でも
 * セットアップ一発で同じ人数が入るよう、そのコピーを database/data/role_requirements.csv
 * として同梱し、取込コマンド ecs:import-role-requirements を呼ぶ。
 * （取込は対象コンテンツの旧行を消して入れ直す＝再実行しても重複しない）
 */
class RoleRequirementSeeder extends Seeder
{
    public function run(): void
    {
        $csv = database_path('data/role_requirements.csv');
        if (! is_file($csv)) {
            $this->command?->warn("必要アサイン人数CSVが見つからないためスキップ: {$csv}");
            return;
        }

        Artisan::call('ecs:import-role-requirements', [
            'path' => $csv,
            '--apply' => true,
        ]);

        $this->command?->info('必要アサイン人数リストを取り込みました（content_role_requirements）。');
    }
}
