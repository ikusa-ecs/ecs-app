<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\Project;
use App\Support\ProjectFormats;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * スタッフ画面の募集一覧が、実施形態のせいで消えないこと（2026-08-28 baba報告）。
 *
 * 【何が起きていたか】
 * 公開したのにスタッフ画面の募集一覧に出てこない。
 * ⚠ ただし「📣 追加募集が◯件出ています」のお知らせだけは出ていた＝**データはあった**。
 *
 * 【原因】
 * サーバーは実施形態を リアル／リアルロング／オンライン の3つにしか振り分けておらず、
 * **ARENA場所貸し・体験会・未入力は空文字**で返していた。
 * 画面はその空文字で対応表を引くので値が見つからず、そこで描画が止まっていた。
 * お知らせは案件を1件ずつ並べる**前**に出しているので、お知らせだけ出て一覧が空になった。
 * ⚠ 画面が真っ白にならないので気づけない種類の不具合。だからテストで見張る。
 *
 * 【直し方】
 * 実施形態の振り分けは正本（ProjectFormats::badgeCode）に任せる＝どんな実施形態でも必ず値が返る。
 * 実施形態の名前もそのまま渡して、バッジにその名前を出す。
 */
class StaffPortalFormatBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-900', 'name' => 'テスト スタッフ', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function publish(string $id, string $format): Project
    {
        return Project::create([
            'id' => $id,
            'project_name' => '会議室',
            'content_names' => ['会議室'],
            'client' => 'テスト株式会社',
            'start_date' => Carbon::today()->copy()->addDays(10)->toDateString(),
            'office' => '東京',
            'format' => $format,
            'status' => '確定',
            'is_recruiting' => true,
            'staff_published' => true,
        ]);
    }

    /**
     * ⚠ どの実施形態でも、バッジの手がかり（fmt）が空にならないこと。
     * 空になると画面側で対応表が引けず、募集一覧がまるごと出なくなる。
     */
    public function test_every_format_gets_a_badge_code(): void
    {
        $staff = $this->staff();

        // 登録で選べる実施形態ぜんぶ＋未入力＋昔の書き方。
        $formats = array_merge(ProjectFormats::ALL, ['', '他拠点→東京 ヘルプ']);
        foreach ($formats as $i => $f) {
            $this->publish('P-F'.$i, $f);
        }

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()->viewData('recruitJobs');

        $this->assertCount(count($formats), $jobs, '公開した案件が募集一覧に出ていない');

        foreach ($jobs as $j) {
            $this->assertNotSame('', $j['fmt'],
                '実施形態「'.$j['fmtText'].'」でバッジの手がかりが空になっている＝画面がここで止まる');
        }
    }

    /** ARENA場所貸しの案件もちゃんと出て、実施形態の名前がそのまま渡る。 */
    public function test_arena_project_is_listed(): void
    {
        $staff = $this->staff();
        $this->publish('P-ARENA', 'ARENA場所貸し');

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()->viewData('recruitJobs');

        $this->assertCount(1, $jobs);
        $this->assertSame('fmt-arena', $jobs[0]['fmt']);
        $this->assertSame('ARENA場所貸し', $jobs[0]['fmtText']);
    }

    /** 実施形態が未入力の案件も出る（入れ忘れで募集が消えない）。 */
    public function test_project_without_a_format_is_listed(): void
    {
        $staff = $this->staff();
        $this->publish('P-NOFMT', '');

        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()->viewData('recruitJobs');

        $this->assertCount(1, $jobs);
        $this->assertSame('fmt-etc', $jobs[0]['fmt']);
    }

    /**
     * 画面側にも受け皿があること。
     * ⚠ 知らない実施形態が来ても、そこで止まらず「その他」で描き続ける。
     */
    public function test_screen_has_a_fallback(): void
    {
        $staff = $this->staff();
        $this->publish('P-X', 'ARENA場所貸し');

        $this->actingAsPerson($staff)->get('/staff-portal')
            ->assertOk()
            ->assertSee('function fmtBadge', false)
            ->assertSee('fbadge.fmt-etc', false);
    }
}
