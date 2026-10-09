<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Project;
use App\Models\ProjectDispatch;
use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 拠点ごとの必要人数（2026-10-09 baba要望「日別ボードで東1 名9 のように」・正本=App\Support\OfficeCounts）。
 * 決めたこと：どちらの拠点からでも直せる／巻き取りでも普通のヘルプでも同じ／数えるのも拠点ごと（A案）／
 * 派遣は登録した拠点の分。
 */
class OfficeCountsTest extends TestCase
{
    use RefreshDatabase;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = Carbon::today()->addDays(5)->format('Y-m-d');
    }

    private function employee(string $office, string $permission = 'employee')
    {
        return PersonFactory::new()->create([
            'role' => 'employee', 'permission' => $permission, 'office' => $office, 'must_onboard' => false,
        ]);
    }

    /** 名古屋が巻き取り（東京からも応援）・運営10名の案件。 */
    private function takenOver(): Project
    {
        $p = ProjectFactory::new()->published()->create([
            'office' => '東京', 'start_date' => $this->date, 'required_count' => 10,
        ]);
        ProjectShare::create(['project_id' => $p->id, 'office' => '名古屋', 'kind' => '巻き取り', 'origin_helps' => true]);

        return $p;
    }

    private function assign(Project $p, string $office): void
    {
        $s = PersonFactory::new()->create(['office' => $office]);
        Assignment::create(['project_id' => $p->id, 'staff_id' => $s->id, 'role' => 'OP', 'status' => '確定', 'date' => $this->date]);
    }

    private function card(Project $p, string $office, $who): array
    {
        return collect($this->actingAsPerson($who)->get('/assign?office='.urlencode($office))->assertOk()->viewData('boardCases'))
            ->firstWhere('id', $p->id);
    }

    public function test_どちらの拠点からでも直せる(): void
    {
        $p = $this->takenOver();

        $this->actingAsPerson($this->employee('名古屋'))
            ->postJson('/assign-publish/office-counts', ['id' => $p->id, 'counts' => ['東京' => 1, '名古屋' => 9]])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['東京' => 1, '名古屋' => 9], $p->fresh()->office_counts);

        $this->actingAsPerson($this->employee('東京'))
            ->postJson('/assign-publish/office-counts', ['id' => $p->id, 'counts' => ['東京' => 2, '名古屋' => null]])
            ->assertOk();
        $this->assertSame(['東京' => 2], $p->fresh()->office_counts, '空にした拠点は分けないに戻る');
    }

    public function test_関わっていない拠点と他拠点の人は直せない(): void
    {
        $p = $this->takenOver();

        $this->actingAsPerson($this->employee('東京'))
            ->postJson('/assign-publish/office-counts', ['id' => $p->id, 'counts' => ['大阪' => 3]])
            ->assertStatus(422);
        $this->actingAsPerson($this->employee('大阪'))
            ->postJson('/assign-publish/office-counts', ['id' => $p->id, 'counts' => ['東京' => 3]])
            ->assertStatus(403);
        $this->assertNull($p->fresh()->office_counts);
    }

    public function test_日別ボードは見ている拠点の人数と人で数える(): void
    {
        $p = $this->takenOver();
        $p->office_counts = ['東京' => 1, '名古屋' => 9];
        $p->save();
        $this->assign($p, '名古屋');
        $this->assign($p, '名古屋');
        ProjectDispatch::create(['project_id' => $p->id, 'agency' => '派遣会社', 'count' => 3, 'status' => '依頼中',
            'requested_on' => $this->date]);

        $tokyo = $this->card($p, '東京', $this->employee('東京', 'manager'));
        $this->assertSame(1, $tokyo['need']);
        $this->assertSame(3, $tokyo['filled'], '名古屋の2人は数えない・派遣3名は登録拠点（東京）の分');
        $this->assertFalse($tokyo['assigned'][0]['here']);

        $nagoya = $this->card($p, '名古屋', $this->employee('名古屋', 'manager'));
        $this->assertSame(9, $nagoya['need']);
        $this->assertSame(2, $nagoya['filled'], '名古屋の2人だけ（派遣は東京の分）');
        $this->assertSame(9, $nagoya['needStaff'], 'スタッフに見せる必要人数も名古屋の分');
    }

    public function test_分けていなければこれまでどおり(): void
    {
        $p = $this->takenOver();
        $this->assign($p, '名古屋');

        $card = $this->card($p, '東京', $this->employee('東京', 'manager'));
        $this->assertSame(10, $card['need']);
        $this->assertSame(1, $card['filled']);
        $this->assertTrue($card['assigned'][0]['here']);
    }

    public function test_ヘルプを外した拠点の数は効かない(): void
    {
        $p = $this->takenOver();
        $p->office_counts = ['東京' => 1, '大阪' => 4];
        $p->save();

        $this->assertSame(['東京' => 1], \App\Support\OfficeCounts::of($p->fresh()));
    }
}
