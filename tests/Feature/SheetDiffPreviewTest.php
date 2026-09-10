<?php

namespace Tests\Feature;

use App\Models\Person;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * アサイン表の取込の下見が「ECSの中身が実際にどう変わるか」を出す（2026-09-10 baba要望）。
 *
 * 【なぜ要るか】
 * ECSとアサイン表の二重管理をしていて、**どこまでECSに反映したか分からない**のが困りごと。
 * これまでの下見は「新規／上書き」までは出せたが、「上書き」と出ている案件が
 * ECSと同じ中身なのか違うのかは分からなかった。
 *
 * ⚠ ここが壊れるといちばん困るのは「変化なし」と出たのに中身が書き換わること。
 *   なので「同じCSVを入れ直したら変化なし」を必ず守る（下のいちばん上のテスト）。
 */
class SheetDiffPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): Person
    {
        return PersonFactory::new()->create([
            'id' => 'E-001', 'name' => '取込担当', 'permission' => 'manager',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    private function staff(string $id, string $name): Person
    {
        return PersonFactory::new()->create([
            'id' => $id, 'name' => $name, 'role' => 'staff', 'permission' => 'staff',
            'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 実物と同じ見出しのCSV（列の順番も実物どおり）。 */
    private function csv(array $rows): UploadedFile
    {
        $header = 'No.,募集,種別,日程,宿泊,コンテンツ,案件規模,営業担当,オンラインツール,配信種別,'
            .'顧客名(代理店名),運営場所,複数開催,集合,解散,拘束,入場,開始,終了,顧客担当名,'
            .'人数,チーム数,運営人数,形式,運営方式,担当,LINE作成,LINE概要送付,引継,ダブチェ,'
            .'運営シート,シート期日,台本,台本期日,音響,ロゴ,カメラ,記事,動画,会場場所,'
            .'集合形式,お酒,物品担当,ケータリング,移動方法,会場種別,備考,D,MC,OP,スタッフ';
        $line = fn (array $r) => implode(',', array_map(
            fn ($v) => str_contains((string) $v, ',')
                ? '"'.str_replace('"', '""', (string) $v).'"'
                : (string) $v,
            $r
        ));

        return UploadedFile::fake()->createWithContent(
            'past.csv', $header."\n".implode("\n", array_map($line, $rows))."\n"
        );
    }

    /** 1行ぶんの値（51列）。指定した列だけ差し替える。 */
    private function row(array $override = []): array
    {
        $base = array_fill(0, 51, '');
        $base[0] = '1';
        $base[3] = '2026-01-20';       // 日程
        $base[5] = '水合戦';            // コンテンツ
        $base[10] = '株式会社テスト';    // 顧客名(代理店名)
        $base[13] = '08:00';           // 集合
        $base[22] = '5';               // 運営人数

        foreach ($override as $i => $v) {
            $base[$i] = $v;
        }

        return $base;
    }

    /** 取り込んでから、同じ中身で下見する。 */
    private function importThenPreview(Person $me, array $first, array $second): array
    {
        $this->actingAsPerson($me)->post('/past-import', ['csv' => $this->csv([$first])])
            ->assertRedirect('/past-import');

        return $this->actingAsPerson($me)->post('/past-import/preview', ['csv' => $this->csv([$second])])
            ->assertOk()->json('rows.0.diff');
    }

    /**
     * 取込画面がちゃんと開いて、差分の数を出す場所があること。
     *
     * ⚠ Bladeの中のJSが壊れても画面は真っ白にならない（開くのにボタンが効かない・表が空になる）
     *   ので、目で見ても気づけない。ここで最低限「開くこと」を見張る。
     */
    public function test_the_import_screen_opens_with_the_diff_counters(): void
    {
        $res = $this->actingAsPerson($this->manager())->get('/past-import')->assertOk();

        $res->assertSee('pjDiffNew', false);
        $res->assertSee('pjDiffChanged', false);
        $res->assertSee('pjDiffSame', false);
        $res->assertSee('ECSとどう違うか');
    }

    /**
     * ⚠ いちばん大事なテスト：同じCSVを入れ直したら「変化なし」。
     *   ここが崩れると毎日「全部変わります」と出て、差分を見る意味がなくなる。
     */
    public function test_the_same_sheet_shows_no_change(): void
    {
        $diff = $this->importThenPreview($this->manager(), $this->row(), $this->row());

        $this->assertSame('same', $diff['kind'],
            '同じ中身なのに「変わります」と出ています：'.json_encode($diff['changes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $diff['changes']);
    }

    /** 時刻の書き方の違い（08:00 と 8:00）は「変わった」にしない。 */
    public function test_the_time_written_differently_is_not_a_change(): void
    {
        $diff = $this->importThenPreview($this->manager(),
            $this->row([13 => '08:00']), $this->row([13 => '8:00']));

        $this->assertSame('same', $diff['kind'],
            '08:00 と 8:00 を別のものとして数えています（既知の罠）。');
    }

    /** 運営人数を直したら「変わります」＋前と後を出す。 */
    public function test_a_changed_value_is_listed_with_before_and_after(): void
    {
        $diff = $this->importThenPreview($this->manager(),
            $this->row([22 => '5']), $this->row([22 => '9']));

        $this->assertSame('changed', $diff['kind']);

        $labels = array_column($diff['changes'], 'label');
        $this->assertContains('運営人数', $labels, '運営人数が変わったのに出ていません。');

        $one = $diff['changes'][array_search('運営人数', $labels, true)];
        $this->assertSame('5', $one['was']);
        $this->assertSame('9', $one['now']);
    }

    /** ECSにまだ無い案件は「新しい案件」。 */
    public function test_a_project_not_in_ecs_is_new(): void
    {
        $me = $this->manager();

        $diff = $this->actingAsPerson($me)->post('/past-import/preview', ['csv' => $this->csv([$this->row()])])
            ->assertOk()->json('rows.0.diff');

        $this->assertSame('new', $diff['kind']);
    }

    /** 人が増えたら、その人の名前を出す。 */
    public function test_an_added_person_is_shown(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田太郎');
        $this->staff('S-002', '鈴木花子');

        // 1回目は山田だけ。2回目は鈴木も並んでいる（50列目＝スタッフ）。
        $diff = $this->importThenPreview($me,
            $this->row([50 => '山田太郎']), $this->row([50 => '山田太郎, 鈴木花子']));

        $this->assertSame('changed', $diff['kind']);
        $this->assertCount(1, $diff['people']['add']);
        $this->assertStringContainsString('鈴木花子', $diff['people']['add'][0]);
        $this->assertSame([], $diff['people']['remove']);
    }

    /** 人が外れたら、その人の名前を出す。 */
    public function test_a_removed_person_is_shown(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田太郎');
        $this->staff('S-002', '鈴木花子');

        $diff = $this->importThenPreview($me,
            $this->row([50 => '山田太郎, 鈴木花子']), $this->row([50 => '山田太郎']));

        $this->assertSame('changed', $diff['kind']);
        $this->assertCount(1, $diff['people']['remove']);
        $this->assertStringContainsString('鈴木花子', $diff['people']['remove'][0]);
    }

    /**
     * ⚠ シートに人が並んでいないときは「人が外れる」と言わない。
     *   取込はシートに出てくる役割の行しか消さないので、言うと嘘の警告になる。
     */
    public function test_an_empty_staff_column_does_not_report_removals(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田太郎');

        $diff = $this->importThenPreview($me,
            $this->row([50 => '山田太郎']), $this->row([50 => '']));

        $this->assertSame([], $diff['people']['remove'],
            'シートに人が並んでいないのに「外れる」と出しています（取込は消しません）。');
        $this->assertSame('same', $diff['kind']);
    }

    /**
     * ポジションを入れ替えたら、前と後を出す（実際のアサイン作業でよくある直し方）。
     *
     * ⚠ 「Dの列から名前を消してMCの列に書いた」ような直し方では、ここは反応しない。
     *   取込は**シートに名前が並んでいる役割の行しか消さない**ので、前のDのアサインは
     *   残ったまま（差分は「MCとして増える」と出る）＝出している内容は本当のこと。
     */
    public function test_swapped_roles_are_shown(): void
    {
        $me = $this->manager();
        $this->staff('S-001', '山田太郎');
        $this->staff('S-002', '鈴木花子');

        // 48列目＝MC、49列目＝OP。2回目は2人を入れ替える。
        $diff = $this->importThenPreview($me,
            $this->row([48 => '山田太郎', 49 => '鈴木花子']),
            $this->row([48 => '鈴木花子', 49 => '山田太郎']));

        $this->assertSame('changed', $diff['kind']);
        $this->assertCount(2, $diff['people']['role'],
            '2人を入れ替えたのに、ポジションが変わったと出ていません。');
        $this->assertSame([], $diff['people']['add'], '入れ替えなのに「増える」と出ています。');
        $this->assertSame([], $diff['people']['remove'], '入れ替えなのに「外れる」と出ています。');
    }
}
