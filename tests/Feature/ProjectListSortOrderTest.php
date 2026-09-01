<?php

namespace Tests\Feature;

use App\Models\Project;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 案件一覧の並び順（2026-09-01 baba要望）。
 *
 * スタッフ公開ボードと同じように「登録順（新しい順）」でも見られるようにした。
 * ＝いま登録した案件がいちばん上に来るので、入れた直後に確認しやすい。
 *
 * ⚠ ここで見張るのは主に「詰め替え漏れ」。
 *   案件一覧はサーバーの値を画面用の形に**手で書き写している**ので、
 *   1行足し忘れると画面側で値が空になり、並べ替えが黙って効かなくなる
 *   （拠点・ケータリングで同じ抜け方を実際にやっている）。
 */
class ProjectListSortOrderTest extends TestCase
{
    use RefreshDatabase;

    private function open()
    {
        return $this->actingAsPerson(
            PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京'])
        )->get('/projects');
    }

    /** 登録日（added）がサーバーから画面へ渡っている。 */
    public function test_the_registered_date_is_passed_to_the_screen(): void
    {
        $p = ProjectFactory::new()->create([
            'project_name' => '登録順テスト',
            'start_date' => Carbon::today()->copy()->addDays(30)->toDateString(),
            'office' => '東京',
        ]);
        // 3日前に登録した、という状態にする。
        Project::where('id', $p->id)->update(['created_at' => Carbon::today()->copy()->subDays(3)]);

        $this->open()->assertOk()->assertViewHas('cases', function ($cases) use ($p) {
            $c = collect($cases)->firstWhere('id', $p->id);

            return $c && ($c['added'] ?? null) === -3;
        });
    }

    /**
     * ⚠ 画面側の詰め替えに added が入っているか。
     *   ここが抜けると、並べ替えの選択肢は出るのに**順番が変わらない**（気づきにくい）。
     */
    public function test_the_screen_copies_the_registered_date(): void
    {
        $blade = (string) file_get_contents(resource_path('views/projects.blade.php'));

        $this->assertStringContainsString('added:(typeof c.added', $blade,
            '案件一覧の詰め替えに added がありません。登録順の並べ替えが効かなくなります。');
    }

    /** 並び順の選択欄と処理が画面にある（@verbatim の中なので消えても気づけない）。 */
    public function test_the_sort_control_is_on_the_screen(): void
    {
        $this->open()->assertOk()
            ->assertSee('id="sortMode"', false)
            ->assertSee('登録順（新しい順・登録したてが上）', false)
            ->assertSee('function applySort', false)
            // 登録順のときは月の見出しを出さない＝畳みも効かせない。
            ->assertSee('flatOrder', false);
    }

    /** 登録日が無い古いデータでも落ちない（0＝今日あつかい）。 */
    public function test_a_project_without_a_registered_date_still_works(): void
    {
        $p = ProjectFactory::new()->create([
            'project_name' => '登録日なし',
            'start_date' => Carbon::today()->copy()->addDays(10)->toDateString(),
            'office' => '東京',
        ]);
        Project::where('id', $p->id)->update(['created_at' => null]);

        $this->open()->assertOk()->assertViewHas('cases', function ($cases) use ($p) {
            $c = collect($cases)->firstWhere('id', $p->id);

            return $c && ($c['added'] ?? null) === 0;
        });
    }
}
