<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * アサイン表：同じ日の案件は「集合がはやい順」に並べる（2026-11-27 小沼さん）。
 * 集合が空の案件はその日のいちばん後ろ。日付の順は今までどおり。
 */
class AssignSheetSameDayOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_同じ日は集合がはやい順・空は最後・日付順はそのまま(): void
    {
        $day = Carbon::today()->addMonth()->startOfMonth()->addDays(9);
        $d1 = $day->format('Y-m-d');
        $d2 = $day->copy()->addDay()->format('Y-m-d');
        $me = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);

        // わざと「おそい→空→はやい」の順で登録する。文字のまま比べると 10:00 が 9:00 より前に来てしまう。
        $mk = fn (string $name, string $date, ?string $time) => ProjectFactory::new()->create([
            'office' => '東京', 'project_name' => $name, 'start_date' => $date, 'start_time' => $time,
        ]);
        $mk('ならびテスト翌日あさ', $d2, '6:00');
        $mk('ならびテストじゅうじ', $d1, '10:00');
        $mk('ならびテストから', $d1, null);
        $mk('ならびテストくじ', $d1, '9:00');
        $mk('ならびテストぜんかく', $d1, '７：３０');

        $this->actingAsPerson($me)
            ->get('/assign-sheet?month='.$day->format('Y-m'))
            ->assertOk()
            ->assertSeeInOrder([
                'ならびテストぜんかく',   // 7:30（全角で書いてあっても読む）
                'ならびテストくじ',       // 9:00
                'ならびテストじゅうじ',   // 10:00
                'ならびテストから',       // 集合が空＝その日の最後
                'ならびテスト翌日あさ',   // 次の日は、集合がはやくても後ろ
            ]);
    }
}
