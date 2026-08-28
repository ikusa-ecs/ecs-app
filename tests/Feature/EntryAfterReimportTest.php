<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Person;
use App\Models\Project;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 【調査】アサイン表を取り込み直したあと、スタッフのエントリーがどうなるか。
 * 「エントリーしても毎回未エントリーになる」との報告（2026-08-28 baba）。
 * 「スタッフが入っていなかったので、もう一度読み込ませた」とのこと。
 */
class EntryAfterReimportTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'role' => 'employee', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'S-800', 'name' => '応募 太郎', 'role' => 'staff',
            'permission' => 'staff', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 月シート（1案件＝横1ブロック）。members に名前を並べる。 */
    private function sheet(array $members = []): UploadedFile
    {
        $blank = fn () => array_fill(0, 24, '');
        $put = function (array $row, array $cells) {
            foreach ($cells as $i => $v) {
                $row[$i] = $v;
            }

            return $row;
        };
        $rows = [];
        $rows[] = $put($blank(), [13 => '1']);
        $rows[] = $put($blank(), [13 => '']);
        $rows[] = $put($blank(), [13 => 'イベント東(リアル)']);
        $rows[] = $put($blank(), [13 => '日程', 16 => '9月13日(日)', 19 => '宿泊', 20 => '無']);
        $rows[] = $put($blank(), [13 => 'コンテンツ', 16 => '水合戦']);
        $rows[] = $put($blank(), [13 => '案件規模', 16 => '小型', 18 => '営業担当', 20 => '馬場 智之']);
        $rows[] = $put($blank(), [13 => '顧客名（代理店名）', 16 => 'テスト株式会社']);
        $rows[] = $put($blank(), [13 => '集合/解散/拘束時間', 16 => '9:00', 18 => '17:00', 20 => '8:00']);
        $rows[] = $put($blank(), [13 => '人数 / チーム数', 16 => '50名', 19 => '10チーム']);
        $rows[] = $put($blank(), [13 => '運営人数 / 形式', 16 => '5名', 19 => '確定']);
        $rows[] = $put($blank(), [13 => '備考', 16 => 'テスト']);
        $rows[] = $put($blank(), [13 => 'NO', 14 => '名前', 17 => 'P', 18 => '巡回', 19 => '備考']);
        foreach ($members as $i => $m) {
            $rows[] = $put($blank(), [13 => (string) ($i + 1), 14 => $m, 17 => 'FC']);
        }
        $line = fn (array $r) => implode(',', array_map('strval', $r));

        return UploadedFile::fake()->createWithContent(
            '東京アサイン表 - 202609.csv', implode("\n", array_map($line, $rows))."\n"
        );
    }

    private function import(Person $me, array $members, string $mode = 'これから')
    {
        return $this->actingAsPerson($me)->post('/past-import', [
            'csv' => $this->sheet($members),
            'period' => '2026-09',
            'office' => '東京',
            'mode' => $mode,
        ]);
    }

    /** 取り込み直しで案件が増えないこと（増えると「別の案件」になってエントリーが消えたように見える）。 */
    public function test_reimport_does_not_create_a_second_project(): void
    {
        $me = $this->manager();

        $this->import($me, []);
        $this->assertSame(1, Project::count());

        // スタッフを1人足して読み込み直す（実際にやった操作）。
        $this->import($me, ['応募 太郎']);

        $this->assertSame(1, Project::count(), '取り込み直しで案件が増えている＝エントリーが別案件に残る');
    }

    /** ⚠ 本題：エントリー済みの案件を取り込み直しても、エントリーが消えないこと。 */
    public function test_reimport_keeps_the_entry(): void
    {
        $me = $this->manager();
        $staff = $this->staff();

        $this->import($me, []);
        $p = Project::first();

        // 公開して、本人がエントリーする。
        $p->update(['staff_published' => true]);
        $this->actingAsPerson($staff)->post('/staff-portal/entry', [
            'project_id' => $p->id, 'action' => 'apply', 'intent' => '希望', 'note' => '入れます',
        ])->assertOk();
        $this->assertSame(1, Application::count());

        // スタッフを足して読み込み直す。
        $this->import($me, ['応募 太郎']);

        $this->assertSame(1, Application::count(), 'エントリーの記録そのものが消えている');

        // 本人の画面でエントリー済みに見えるか。
        $jobs = $this->actingAsPerson($staff)->get('/staff-portal')->assertOk()->viewData('recruitJobs');
        $mine = collect($jobs)->firstWhere('id', $p->id);

        $this->assertNotNull($mine, '取り込み直したら募集一覧から案件が消えた');
        $this->assertTrue($mine['applied'], '取り込み直したらエントリーが外れて見えている');
    }

    /**
     * ⚠ ここが今回の原因。取り込み直すと公開が取り消されていた（2026-08-28 baba報告）。
     * スタッフを1人足すために読み込み直しただけで、募集がスタッフの画面から消えていた。
     */
    public function test_reimport_keeps_it_published(): void
    {
        $me = $this->manager();

        $this->import($me, []);
        $p = Project::first();
        $p->update(['staff_published' => true]);

        $this->import($me, ['応募 太郎']);

        $this->assertTrue((bool) $p->fresh()->staff_published, '取り込み直したら公開が取り消された');
    }

    /**
     * ⚠ ただし「過去」で入れてしまったものを「これから」で入れ直したときは、非公開に戻す。
     * 過去あつかいは公開済みで入るので、そのままだと**これからの案件の
     * クライアント名・会場がスタッフ全員に見えたまま**になる。
     */
    public function test_switching_from_past_to_future_unpublishes(): void
    {
        $me = $this->manager();

        $this->import($me, ['応募 太郎'], '過去');
        $p = Project::first();
        $this->assertTrue((bool) $p->staff_published, '過去あつかいは公開済みで入る');

        $this->import($me, ['応募 太郎'], 'これから');

        $this->assertFalse((bool) $p->fresh()->staff_published,
            '過去→これから に入れ直したのに公開されたままになっている');
    }
}
