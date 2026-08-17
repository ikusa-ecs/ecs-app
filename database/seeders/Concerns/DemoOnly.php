<?php

namespace Database\Seeders\Concerns;

/**
 * 見本（ダミー）データ用の安全装置。
 *
 * ねらい：本番DBに架空の社員・案件が混ざる事故を防ぐ。
 * これまでは各シーダーに「本番には入れない」とコメントで書いてあるだけで、
 * 実際に止める仕組みが無かった（`php artisan db:seed` を本番で打つと入ってしまう）。
 *
 * 使い方：見本データを入れるシーダーの run() 冒頭に必ず1行入れる。
 *
 *   if ($this->demoBlocked()) { return; }
 *
 * これで DemoSeeder 経由でも、`db:seed --class=PersonSeeder` の直接呼び出しでも、
 * 許可が無い環境では何も入らない（＝入口をふさいでも裏口が残る、を避ける）。
 *
 * 許可の判定は config('ecs.seed_demo')＝.env の ECS_SEED_DEMO。
 * 未設定なら「本番（APP_ENV=production）以外なら許可」に倒れるので、
 * 本番は .env に何も書かなくても安全側になり、
 * 自分のPCとテストサーバーは今までどおり見本が入る（確認が止まらないように）。
 */
trait DemoOnly
{
    /**
     * 見本データを入れてはいけない環境か。
     * true のときは理由を表示して、呼び出し元は何もせず return する。
     */
    protected function demoBlocked(): bool
    {
        if (config('ecs.seed_demo', false)) {
            return false;
        }

        $name = class_basename(static::class);
        $this->command?->warn("スキップ: {$name}（見本データのため）。入れるには .env に ECS_SEED_DEMO=true");

        return true;
    }
}
