<?php

namespace Tests\Feature;

use App\Support\SubDateProject;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 本番案件から「予備日・リハ日・前日設営」を作る（2026-09-28 baba要望）。
 *
 * 【決めたこと（2026-09-28 baba＝2案）】
 *   ・案件の情報だけ引き継ぐ（顧客・会場・コンテンツ）。
 *   ・**時間と人数は空**にする。本番とは別ものなので、コピーされていると
 *     「直したつもりで直っていない」が起きる。
 *   ・アサインした人は引き継がない。
 *
 * ⚠ 何を空にするかの正本＝App\Support\SubDateProject::BLANK。
 *   画面に項目を書き並べない（片方だけ直すと必ず食い違う）。
 */
class SubDateProjectTest extends TestCase
{
    use RefreshDatabase;

    private function manager()
    {
        return PersonFactory::new()->create([
            'permission' => 'manager', 'office' => '東京', 'must_onboard' => false,
        ]);
    }

    /** 本番案件（引き継ぐ中身と、引き継がない中身の両方を入れておく）。 */
    private function honban()
    {
        return ProjectFactory::new()->create([
            'office' => '東京',
            'date_type' => '本番',
            'project_name' => '水合戦',
            // ⚠ 案件名（コンテンツ）はタグで持っている。ここを入れないと引き継ぎを確かめられない。
            'content_names' => ['水合戦'],
            'client' => '株式会社みほん',
            'agency' => 'みほん代理店',
            'location' => '東京都渋谷区1-1-1',
            'operation_place' => '屋上広場',
            // ここから下は引き継がない（時間と人数）
            'start_time' => '9:00',
            'end_time' => '18:00',
            'event_enter_time' => '10:00',
            'event_start_time' => '11:00',
            'event_end_time' => '17:00',
            'required_count' => 12,
            'ikusa_count' => 8,
            'guest_count' => 100,
            'team_count' => 10,
        ]);
    }

    private function openSub(string $type, string $id)
    {
        return $this->actingAsPerson($this->manager())
            ->get('/project-form?copy='.urlencode($id).'&sub='.urlencode($type));
    }

    /** 顧客・会場・コンテンツは引き継ぐ。 */
    public function test_案件の情報は引き継ぐ(): void
    {
        $p = $this->honban();

        $e = $this->openSub('予備日', $p->id)->assertOk()->original->getData()['editProject'];

        $this->assertSame('株式会社みほん', $e['client']);
        $this->assertSame('みほん代理店', $e['agency']);
        $this->assertSame('東京都渋谷区1-1-1', $e['location']);
        $this->assertSame('屋上広場', $e['operation_place']);
        $this->assertContains('水合戦', $e['content_names']);
    }

    /** 時間と人数は空。⚠ ここが埋まっていると「直したつもりで直っていない」が起きる。 */
    public function test_時間と人数は空(): void
    {
        $p = $this->honban();

        $e = $this->openSub('予備日', $p->id)->assertOk()->original->getData()['editProject'];

        foreach (SubDateProject::BLANK as $f) {
            $this->assertSame('', (string) $e[$f], $f.' は空にする（本番とは別もの）');
        }
        foreach (SubDateProject::BLANK_FLAGS as $f) {
            $this->assertFalse((bool) $e[$f], $f.' のチェックも外す');
        }
        // 開催日は複製のしくみが空にしている（二重登録を防ぐため）。
        $this->assertSame('', (string) $e['start_date']);
    }

    /** 種別と紐づく本番案件が、はじめから入っている。 */
    public function test_種別と紐づけが入っている(): void
    {
        $p = $this->honban();

        foreach (SubDateProject::TYPES as $t) {
            $e = $this->openSub($t, $p->id)->assertOk()->original->getData()['editProject'];
            $this->assertSame($t, $e['date_type'], $t.' として開く');
            $this->assertSame($p->id, $e['parent_project_id'], '紐づく本番案件が入っている');
        }
    }

    /** 募集は引き継がない（作った瞬間にスタッフへ出てしまうのを防ぐ）。 */
    public function test_募集は引き継がない(): void
    {
        $p = ProjectFactory::new()->create([
            'office' => '東京', 'date_type' => '本番', 'is_recruiting' => true,
        ]);

        $e = $this->openSub('リハ日', $p->id)->assertOk()->original->getData()['editProject'];

        $this->assertFalse((bool) $e['is_recruiting']);
    }

    /** 元の本番案件は一切変わらない（読むだけ）。 */
    public function test_元の本番案件は変わらない(): void
    {
        $p = $this->honban();

        $this->openSub('前日設営', $p->id)->assertOk();

        $p->refresh();
        $this->assertSame('9:00', $p->start_time);
        $this->assertSame(12, $p->required_count);
        $this->assertSame('本番', $p->date_type);
    }

    /** 知らない種別は無視する（ふつうの複製として開く）。 */
    public function test_知らない種別は複製あつかい(): void
    {
        $p = $this->honban();

        $e = $this->actingAsPerson($this->manager())
            ->get('/project-form?copy='.urlencode($p->id).'&sub=でたらめ')
            ->assertOk()->original->getData()['editProject'];

        $this->assertSame('本番', $e['date_type'], '種別は変えない');
        $this->assertSame('9:00', $e['start_time'], 'ふつうの複製なので時間は残る');
    }

    /** 本番案件の編集画面に、作るための入口（3つ）が出る。 */
    public function test_本番案件の編集画面に入口が出る(): void
    {
        $p = $this->honban();

        $html = $this->actingAsPerson($this->manager())
            ->get('/project-form?project='.urlencode($p->id))->assertOk()->getContent();

        foreach (SubDateProject::TYPES as $t) {
            $this->assertStringContainsString('&sub='.rawurlencode($t), $html, $t.' を作る入口');
        }
    }

    /** 予備日の編集画面には入口を出さない（予備日の予備日は作らない）。 */
    public function test_予備日の編集画面には入口を出さない(): void
    {
        $honban = $this->honban();
        $sub = ProjectFactory::new()->create([
            'office' => '東京', 'date_type' => '予備日', 'parent_project_id' => $honban->id,
        ]);

        $html = $this->actingAsPerson($this->manager())
            ->get('/project-form?project='.urlencode($sub->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('この本番案件から作る', $html);
    }
}
