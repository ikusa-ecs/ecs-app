<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectShare;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 名古屋のチェックバック（11/27）で、登録拠点と巻き取りの印を直す処理。
 * 対象＝database/migrations/2026_11_27_000002_apply_nagoya_checkback_offices.php
 */
class NagoyaCheckbackOfficesTest extends TestCase
{
    use RefreshDatabase;

    public function test_offices_and_shares_follow_the_answers(): void
    {
        $make = fn (string $id, array $more = []) => ProjectFactory::new()->create(['id' => $id, 'office' => '東京'] + $more);
        $make('P-2024-0264');                       // 答え「名古屋」
        $make('P-2025-0360');                       // 名古屋案件、東京実施
        $make('P-2026-0901');                       // 名古屋案件、東京・大阪実施
        $make('P-2025-0808');                       // 東京案件、名古屋実施
        $make('P-2026-1277', ['date_type' => '本番']);
        $make('P-2026-1276', ['date_type' => '本番', 'office' => '名古屋']);
        $other = $make('P-2026-9999');              // 一覧に無い案件＝触らない
        // 名古屋へ移す案件に付いていた「名古屋がヘルプ」は、持ち主への印になるので消える。
        ProjectShare::create(['project_id' => 'P-2024-0264', 'office' => '名古屋', 'kind' => 'ヘルプ']);

        // ⚠ テスト用のDBを作るときに1回流れている（そのときは案件が無い＝何もしない）。ここでもう一度流す。
        $migration = require database_path('migrations/2026_11_27_000002_apply_nagoya_checkback_offices.php');
        $migration->up();
        $migration->up();   // 2回流しても同じ

        $shares = fn (string $id) => ProjectShare::where('project_id', $id)->orderBy('office')
            ->get()->map(fn ($s) => $s->office.':'.$s->kind)->all();

        $this->assertSame('名古屋', Project::find('P-2024-0264')->office);
        $this->assertSame([], $shares('P-2024-0264'));

        $this->assertSame('名古屋', Project::find('P-2025-0360')->office);
        $this->assertSame(['東京:巻き取り'], $shares('P-2025-0360'));

        $this->assertSame('名古屋', Project::find('P-2026-0901')->office);
        $this->assertEqualsCanonicalizing(['東京:巻き取り', '大阪:巻き取り'], $shares('P-2026-0901'));

        $this->assertSame('東京', Project::find('P-2025-0808')->office);
        $this->assertSame(['名古屋:巻き取り'], $shares('P-2025-0808'));

        $rehearsal = Project::find('P-2026-1276');
        $this->assertSame('リハ日', $rehearsal->date_type);
        $this->assertSame('P-2026-1277', $rehearsal->parent_project_id);

        $this->assertSame('東京', $other->fresh()->office);
        $this->assertSame([], $shares('P-2026-9999'));
    }
}
