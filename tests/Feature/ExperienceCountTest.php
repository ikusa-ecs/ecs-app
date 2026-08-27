<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Person;
use App\Models\Project;
use App\Support\ExperienceCount;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 経験回数の自動集計（App\Support\ExperienceCount）。2026-08-27 baba要望。
 *
 * 数え方（baba決定）：
 *  ・確定のアサインで、開催日が過ぎたものだけ（仮・これからの案件は数えない）
 *  ・キャンセルの案件は数えない
 *  ・コンテンツごと／ポジションごと／コンテンツ×ポジション の3つを出す
 *
 * ⚠ 表に保存せず毎回 assignments から数える（写しは必ず腐るため）。
 */
class ExperienceCountTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $id): Person
    {
        return PersonFactory::new()->staff()->create(['id' => $id, 'office' => '東京']);
    }

    /**
     * 案件を作る。$daysAgo が正なら過去、負なら未来。
     */
    private function project(string $id, array $contents, int $daysAgo, array $extra = []): Project
    {
        return Project::create(array_merge([
            'id' => $id,
            'project_name' => $contents[0] ?? '案件',
            'content_names' => $contents,
            'start_date' => Carbon::today()->copy()->subDays($daysAgo)->toDateString(),
            'office' => '東京',
            'status' => '確定',
        ], $extra));
    }

    private function assign(string $projectId, string $staffId, ?string $role, string $status = '確定'): Assignment
    {
        $p = Project::findOrFail($projectId);

        return Assignment::create([
            'project_id' => $projectId,
            'staff_id' => $staffId,
            'date' => $p->start_date,
            'role' => $role,
            'status' => $status,
        ]);
    }

    /** コンテンツごと・ポジションごと・組み合わせが数えられる。 */
    public function test_counts_by_content_and_role(): void
    {
        $s = $this->staff('S-301');

        $this->project('P-1', ['水合戦'], 30);
        $this->project('P-2', ['水合戦'], 20);
        $this->project('P-3', ['謎解き'], 10);

        $this->assign('P-1', $s->id, 'MC');
        $this->assign('P-2', $s->id, 'FC');
        $this->assign('P-3', $s->id, 'MC');

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame(3, $e['projects'], '通算＝案件3件');

        // コンテンツごと（多い順）。
        $this->assertSame('水合戦', $e['byContent'][0]['name']);
        $this->assertSame(2, $e['byContent'][0]['count']);
        $this->assertSame('謎解き', $e['byContent'][1]['name']);
        $this->assertSame(1, $e['byContent'][1]['count']);

        // ポジションごと（決まった並び＝MC が FC より先）。
        $roles = array_column($e['byRole'], 'count', 'role');
        $this->assertSame(2, $roles['MC']);
        $this->assertSame(1, $roles['FC']);

        // コンテンツ×ポジション。
        $this->assertSame(1, $e['byContentRole']['水合戦']['MC']);
        $this->assertSame(1, $e['byContentRole']['水合戦']['FC']);
        $this->assertSame(1, $e['byContentRole']['謎解き']['MC']);
    }

    /** ⚠ 仮のアサインは数えない（声掛け中は「経験した」ではない）。 */
    public function test_tentative_assignment_is_not_counted(): void
    {
        $s = $this->staff('S-302');
        $this->project('P-1', ['水合戦'], 30);
        $this->assign('P-1', $s->id, 'MC', '仮');

        $this->assertSame(0, ExperienceCount::forStaff($s->id)['projects']);
    }

    /** ⚠ これからの案件は数えない（まだ出ていない）。 */
    public function test_future_project_is_not_counted(): void
    {
        $s = $this->staff('S-303');
        $this->project('P-1', ['水合戦'], -10);   // 10日後
        $this->assign('P-1', $s->id, 'MC');

        $this->assertSame(0, ExperienceCount::forStaff($s->id)['projects']);
    }

    /** ⚠ キャンセルの案件は数えない（実施していない）。 */
    public function test_cancelled_project_is_not_counted(): void
    {
        $s = $this->staff('S-304');
        $this->project('P-1', ['水合戦'], 30, ['is_cancelled' => true]);
        $this->assign('P-1', $s->id, 'MC');

        $this->assertSame(0, ExperienceCount::forStaff($s->id)['projects']);
    }

    /**
     * 1つの案件に複数コンテンツがあれば、それぞれに1回足す（両方やったので両方の経験）。
     * ⚠ そのため「コンテンツごとの合計」は通算と一致しない。
     */
    public function test_multiple_contents_count_for_each(): void
    {
        $s = $this->staff('S-305');
        $this->project('P-1', ['水合戦', 'BBQ'], 30);
        $this->assign('P-1', $s->id, 'FC');

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame(1, $e['projects'], '通算は1件');
        $this->assertSame(2, count($e['byContent']), 'コンテンツは2つに数える');
        $this->assertSame(1, array_column($e['byContent'], 'count', 'name')['水合戦']);
        $this->assertSame(1, array_column($e['byContent'], 'count', 'name')['BBQ']);
    }

    /**
     * 同じ案件で複数日あっても「回数」は1回。出勤した日数は days で別に返す。
     * ⚠ assignments は「案件×人×日」で1行なので、行数を数えると2日案件が2回になる。
     */
    public function test_multi_day_project_counts_once(): void
    {
        $s = $this->staff('S-306');
        $p = $this->project('P-1', ['水合戦'], 30);

        Assignment::create([
            'project_id' => 'P-1', 'staff_id' => $s->id,
            'date' => $p->start_date, 'role' => 'FC', 'status' => '確定',
        ]);
        Assignment::create([
            'project_id' => 'P-1', 'staff_id' => $s->id,
            'date' => Carbon::parse($p->start_date)->addDay()->toDateString(),
            'role' => 'FC', 'status' => '確定',
        ]);

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame(1, $e['projects'], '回数は案件の数＝1回');
        $this->assertSame(2, $e['days'], '出勤した日数は2日');
        $this->assertSame(1, $e['byContent'][0]['count']);
    }

    /**
     * ポジションが空の行は「ポジションごと」に数えないが、通算には数える
     * （現場に出たことは事実なので）。
     */
    public function test_missing_role_still_counts_in_total(): void
    {
        $s = $this->staff('S-307');
        $this->project('P-1', ['水合戦'], 30);
        $this->assign('P-1', $s->id, null);

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame(1, $e['projects']);
        $this->assertSame([], $e['byRole'], 'ポジションが分からないので役割には数えない');
        $this->assertSame(1, $e['byContent'][0]['count']);
    }

    /** 最後にやった日が分かる。 */
    public function test_last_date_is_the_newest(): void
    {
        $s = $this->staff('S-308');
        $this->project('P-1', ['水合戦'], 100);
        $this->project('P-2', ['水合戦'], 5);
        $this->assign('P-1', $s->id, 'FC');
        $this->assign('P-2', $s->id, 'FC');

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame(
            Carbon::today()->copy()->subDays(5)->toDateString(),
            $e['byContent'][0]['last']
        );
    }

    /** まとめて数えられる（一覧で1人ずつ引かないため）。 */
    public function test_many_staff_at_once(): void
    {
        $a = $this->staff('S-309');
        $b = $this->staff('S-310');
        $this->project('P-1', ['水合戦'], 30);
        $this->assign('P-1', $a->id, 'MC');

        $out = ExperienceCount::forMany([$a->id, $b->id]);

        $this->assertSame(1, $out[$a->id]['projects']);
        $this->assertSame(0, $out[$b->id]['projects'], 'アサインが無い人も0で返る');
    }

    /** コンテンツ名が空の案件は、案件名で代用して数える。 */
    public function test_falls_back_to_project_name(): void
    {
        $s = $this->staff('S-311');
        $this->project('P-1', [], 30, ['project_name' => '（コンテンツ未定）']);
        $this->assign('P-1', $s->id, 'FC');

        $e = ExperienceCount::forStaff($s->id);

        $this->assertSame('（コンテンツ未定）', $e['byContent'][0]['name']);
    }

    /** 名簿の画面に経験回数が渡っている（スタッフ・社員の両方）。 */
    public function test_screens_receive_the_counts(): void
    {
        $me = PersonFactory::new()->create([
            'role' => 'employee', 'permission' => 'admin', 'office' => '東京', 'must_onboard' => false,
        ]);
        $s = $this->staff('S-320');
        $this->project('P-1', ['水合戦'], 30);
        $this->assign('P-1', $s->id, 'MC');

        $staffRes = $this->actingAsPerson($me)->get('/staff')->assertOk();
        $staffRes->assertViewHas('experience', function ($e) use ($s) {
            return ($e[$s->id]['projects'] ?? 0) === 1;
        });
        $this->assertStringContainsString('ECS_EXPERIENCE', $staffRes->getContent());

        $empRes = $this->actingAsPerson($me)->get('/employees')->assertOk();
        $empRes->assertViewHas('experience');
        $this->assertStringContainsString('experienceAutoHtml', $empRes->getContent());
    }
}
