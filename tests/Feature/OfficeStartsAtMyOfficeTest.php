<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 「拠点を選べる画面は、はじめ自分の拠点で開く」を守るテスト（2026-09-10 baba要望）。
 *
 * それまでは管理者以上だと**全拠点**で開いていたので、東京の担当者が
 * 他拠点の案件まで混ざった一覧を毎回見ていた（自分の拠点に絞り直す手間があった）。
 *
 * 決まりは3つだけ：
 *   ・`?office=` なし        … 自分の拠点
 *   ・`?office=all`          … 全拠点（わざわざ選んだとき）
 *   ・`?office=大阪` のように … その拠点
 *
 * ⚠ 「全拠点」を空文字にしないこと。月を動かすリンクなどで消えて、
 *    自分の拠点に戻ってしまう（`OfficeScope::ALL` が正本）。
 */
class OfficeStartsAtMyOfficeTest extends TestCase
{
    use RefreshDatabase;

    /** 近い日付（画面の表示期間に必ず入る日）。 */
    private function soon(int $plus = 2): string
    {
        return Carbon::today()->addDays($plus)->format('Y-m-d');
    }

    /** 拠点を選べる画面は、何も選ばなければ自分の拠点で開く。 */
    public function test_screens_start_at_my_office(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '大阪']);
        $osaka = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon(3)]);

        // ⚠ `officeScope` を画面に渡している画面だけを見る（渡していない画面は中身で確かめる）。
        foreach (['/dashboard', '/assign', '/entries', '/pickup', '/assign-director'] as $url) {
            $data = $this->actingAsPerson($manager)->get($url)
                ->assertOk("画面 {$url} が開けませんでした")
                ->original->getData();

            $this->assertSame('大阪', $data['officeScope'], "{$url} は自分の拠点で開くこと");
        }

        // 念のため、中身も自分の拠点だけになっていること（日別ボードで確認）。
        $ids = collect(
            $this->actingAsPerson($manager)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->pluck('id')->all();
        $this->assertContains($osaka->id, $ids);
        $this->assertNotContains($tokyo->id, $ids);

        // 案件一覧も同じ（この画面は officeScope を渡さないので、中身と拠点バッジで確かめる）。
        $list = $this->actingAsPerson($manager)->get('/projects')->assertOk()->original->getData();
        $this->assertSame([$osaka->id], collect($list['cases'])->pluck('id')->all());
        $this->assertFalse($list['showOfficeBadge'], '1拠点だけを見ているので拠点バッジは出さない');
    }

    /** 「全拠点」を選べば、これまでどおり全部見られる。 */
    public function test_all_offices_is_still_available(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '大阪']);
        $osaka = ProjectFactory::new()->create(['office' => '大阪', 'start_date' => $this->soon()]);
        $tokyo = ProjectFactory::new()->create(['office' => '東京', 'start_date' => $this->soon(3)]);

        $data = $this->actingAsPerson($manager)->get('/assign?office=all')
            ->assertOk()->original->getData();

        $this->assertNull($data['officeScope'], 'office=all＝全拠点');
        $this->assertEqualsCanonicalizing(
            [$osaka->id, $tokyo->id],
            collect($data['boardCases'])->pluck('id')->all()
        );
    }

    /**
     * 拠点を入れ忘れた案件は「東京」あつかいで残す。
     * ⚠ ここが抜けていると、はじめの表示が自拠点になった時点で
     *    拠点未設定の案件がどの画面からも消える。
     */
    public function test_projects_without_office_are_treated_as_tokyo(): void
    {
        $tokyoManager = PersonFactory::new()->manager()->create(['office' => '東京']);
        $blank = ProjectFactory::new()->create(['office' => null, 'start_date' => $this->soon()]);

        $ids = collect(
            $this->actingAsPerson($tokyoManager)->get('/assign')->assertOk()->original->getData()['boardCases']
        )->pluck('id')->all();

        $this->assertContains($blank->id, $ids, '拠点が空の案件は東京の人に見えること');
    }

    /** 集計ダッシュボードも自分の拠点で開く（全拠点は office=all）。 */
    public function test_stats_starts_at_my_office(): void
    {
        $manager = PersonFactory::new()->manager()->create(['office' => '名古屋']);

        $mine = $this->actingAsPerson($manager)->get('/stats')
            ->assertOk()->original->getData();
        $this->assertSame('名古屋', $mine['scopeOffice'], '集計も自分の拠点で開くこと');

        $all = $this->actingAsPerson($manager)->get('/stats?office=all')
            ->assertOk()->original->getData();
        $this->assertSame('', $all['scopeOffice'], 'office=all＝全拠点');
    }

    /** マスタ管理の「拠点ごとの選択肢」も自分の拠点から始める。 */
    public function test_master_office_options_start_at_my_office(): void
    {
        $admin = PersonFactory::new()->admin()->create(['office' => '大阪']);

        $data = $this->actingAsPerson($admin)->get('/masters')
            ->assertOk()->original->getData();

        $this->assertSame('大阪', $data['optionOffice'], '拠点ごとの選択肢も自分の拠点から');
    }
}
