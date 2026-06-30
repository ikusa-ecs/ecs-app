<?php

namespace Database\Seeders;

use App\Models\Content;
use Illuminate\Database\Seeder;

/**
 * コンテンツ・マスタの見本データ。
 * モック（cases.js / people.js）に出てくる催し物を一覧化したもの。
 * ※開発・動作確認用のダミー。本番には入れない。
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $contents = [
            ['id' => 'CT-001', 'content_name' => '運動会',         'category' => '盛り上げ系', 'is_physical' => true],
            ['id' => 'CT-002', 'content_name' => '水合戦',         'category' => '盛り上げ系', 'is_physical' => true],
            ['id' => 'CT-003', 'content_name' => '縁日',           'category' => '盛り上げ系', 'is_physical' => false],
            ['id' => 'CT-004', 'content_name' => '懇親会運営',     'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-005', 'content_name' => '表彰式',         'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-006', 'content_name' => '周年式典',       'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-007', 'content_name' => 'ボードゲーム大会', 'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-008', 'content_name' => 'クイズ大会',     'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-009', 'content_name' => '新歓イベント',   'category' => '盛り上げ系', 'is_physical' => false],
            ['id' => 'CT-010', 'content_name' => '防災フェス',     'category' => '盛り上げ系', 'is_physical' => true],
            ['id' => 'CT-011', 'content_name' => 'フェス設営',     'category' => '真面目系',   'is_physical' => true],
            ['id' => 'CT-012', 'content_name' => 'ワークショップ系', 'category' => '真面目系',   'is_physical' => false],
            ['id' => 'CT-099', 'content_name' => 'その他',         'category' => null,          'is_physical' => false],
        ];

        foreach ($contents as $c) {
            // updateOrCreate＝同じIDが既にあれば更新・無ければ作成（重複登録を防ぐ）。
            Content::updateOrCreate(['id' => $c['id']], $c + ['active' => true]);
        }
    }
}
