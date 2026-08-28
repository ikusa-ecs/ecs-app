<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectShare;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件登録で「他の拠点にお願いする（ヘルプ／巻き取り）」を決められる（2026-08-28 baba要望）。
 *
 * 【なぜ要るか】
 * 「東京で取った案件を福岡に巻き取ってもらう」が登録できなかった。
 * ⚠ project_shares に書ける唯一の入口（アサイン表の「自拠点にコピー」）が
 *   共有先を**押した人の拠点に固定**していたため、相手の拠点の人が自分で押すのを待つしかなかった。
 *   いまECSを使っているのは東京と名古屋だけなので、それでは記録できない。
 *
 * ⚠ 案件は**複製しない**。projects.office（取ってきた拠点）はそのままで、印だけ残す。
 */
class ProjectShareFromFormTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $office = '東京'): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '管理者', 'role' => 'employee',
            'permission' => 'manager', 'office' => $office, 'must_onboard' => false,
        ]);
    }

    /** 案件登録フォームに送る最低限の内容（確定で保存できる形）。 */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'intent' => 'publish',
            'content_names' => '水合戦',
            'client' => 'テスト株式会社',
            'start_date' => Carbon::today()->copy()->addDays(20)->toDateString(),
            'required_count' => 8,
            'office' => '東京',
        ], $extra);
    }

    /** 東京で取った案件を、福岡に「巻き取り」でお願いする印が付く。 */
    public function test_can_ask_another_office_to_take_over(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '福岡',
            'share_kind' => '巻き取り',
        ]))->assertRedirect('/projects');

        $p = Project::first();
        $this->assertSame('東京', $p->office, '案件を取ってきた拠点は東京のまま（複製もしない）');

        $share = ProjectShare::where('project_id', $p->id)->first();
        $this->assertNotNull($share, '福岡にお願いした印が付いていない');
        $this->assertSame('福岡', $share->office);
        $this->assertSame('巻き取り', $share->kind);
    }

    /** ヘルプでもお願いできる。 */
    public function test_can_ask_for_help(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '名古屋', 'share_kind' => 'ヘルプ',
        ]))->assertRedirect('/projects');

        $this->assertSame('ヘルプ', ProjectShare::first()->kind);
    }

    /** 「お願いしない」を選ぶと、前に付けた印が消える。 */
    public function test_clearing_removes_the_share(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '福岡', 'share_kind' => '巻き取り',
        ]));
        $p = Project::first();
        $this->assertSame(1, ProjectShare::count());

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'project_id' => $p->id, 'share_office' => '', 'share_kind' => '巻き取り',
        ]));

        $this->assertSame(0, ProjectShare::count(), '「お願いしない」にしたのに印が残っている');
    }

    /** 相手を選び直したら、前の相手の印は消える（1案件につきひとつ）。 */
    public function test_changing_the_office_replaces_the_share(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '福岡', 'share_kind' => '巻き取り',
        ]));
        $p = Project::first();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'project_id' => $p->id, 'share_office' => '大阪', 'share_kind' => 'ヘルプ',
        ]));

        $this->assertSame(1, ProjectShare::count());
        $this->assertSame('大阪', ProjectShare::first()->office);
    }

    /** ⚠ 自分の拠点には頼めない（意味のない印を作らない）。 */
    public function test_cannot_share_to_its_own_office(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'office' => '東京', 'share_office' => '東京', 'share_kind' => '巻き取り',
        ]));

        $this->assertSame(0, ProjectShare::count());
    }

    /** ⚠ 拠点マスタに無い名前は受け付けない（タイポでどこにも出てこない印を作らない）。 */
    public function test_unknown_office_is_ignored(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '沖縄', 'share_kind' => '巻き取り',
        ]));

        $this->assertSame(0, ProjectShare::count());
    }

    /**
     * ⚠ 登録拠点も拠点マスタに無い名前は受け付けない。
     * 受け付けてしまうと、その案件がどの拠点からも見えなくなる。
     */
    public function test_unknown_own_office_falls_back(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload(['office' => 'どこか']));

        $this->assertSame('東京', Project::first()->office, '知らない拠点名がそのまま保存されている');
    }

    /** 欄そのものが送られてこない経路（取込など）では、既にある印を勝手に消さない。 */
    public function test_missing_field_keeps_the_existing_share(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '福岡', 'share_kind' => '巻き取り',
        ]));
        $p = Project::first();

        // share_office を送らずに保存し直す。
        $this->actingAsPerson($me)->post('/project-form', $this->payload(['project_id' => $p->id]));

        $this->assertSame(1, ProjectShare::count(), '欄が無い保存で印まで消えてしまった');
    }

    /** 編集で開いたとき、いまの設定がフォームに渡る。 */
    public function test_edit_screen_shows_the_current_share(): void
    {
        $me = $this->manager();

        $this->actingAsPerson($me)->post('/project-form', $this->payload([
            'share_office' => '福岡', 'share_kind' => '巻き取り',
        ]));
        $p = Project::first();

        $this->actingAsPerson($me)->get('/project-form?project='.$p->id)
            ->assertOk()
            ->assertViewHas('editProject', fn ($e) => $e['shareOffice'] === '福岡' && $e['shareKind'] === '巻き取り')
            // ⚠ Blade の @verbatim の切れ目でスクリプトが途中から消えても真っ白にならない＝見張る。
            ->assertSee('function buildShareOptions', false)
            ->assertSee('id="shareOffice"', false);
    }

    /** 拠点マスタに福岡がある（無いと選べない）。 */
    public function test_fukuoka_is_in_the_office_master(): void
    {
        $this->assertTrue(Office::where('name', '福岡')->where('active', true)->exists());
    }
}
