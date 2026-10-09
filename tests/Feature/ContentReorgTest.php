<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ContentRoleRequirement;
use App\Models\Project;
use App\Support\ContentReorg;
use Database\Factories\PersonFactory;
use Database\Factories\ProjectFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * コンテンツ台帳の整理（2026-10-09・正本=App\Support\ContentReorg）。
 * 守ること：IDと名前が案と一致するものだけ直す／案件は消さない／名前を残す指定は案件名をそのまま。
 */
class ContentReorgTest extends TestCase
{
    use RefreshDatabase;

    private function c(string $id, string $name): void
    {
        Content::create(['id' => $id, 'content_name' => $name, 'active' => true]);
    }

    private function p(string $id, array $ids, array $names, array $extra = []): Project
    {
        return ProjectFactory::new()->create(array_merge([
            'id' => $id, 'project_name' => implode('・', $names), 'content_ids' => $ids, 'content_names' => $names,
        ], $extra));
    }

    public function test_案のとおりに直す(): void
    {
        $this->c('CT-031', 'ロケットPDCA');
        $this->c('CT-053', 'ロケットPDCA＋あそ研');
        $this->c('CT-025', '防災ヒーロー');
        $this->c('CT-191', '板橋区役所_いたばし防災＋フェア');
        $this->c('CT-100', 'AIワークショップ');
        $this->c('CT-110', '謎パorコンセンサス');
        $this->c('CT-045', '格付け');
        $this->c('CT-155', '格付けバトル');
        $this->c('CT-172', '前日設営');
        $this->c('CT-125', 'ファミリーフェス');
        $this->c('CT-054', 'ちがう名前');   // 案と名前が違う＝触らない
        ContentRoleRequirement::create(['content_id' => 'CT-155', 'scale' => '小型', 'position' => 'D', 'count' => 1]);

        $a = $this->p('P-1', ['CT-053'], ['ロケットPDCA＋あそ研']);
        $b = $this->p('P-2', ['CT-191'], ['板橋区役所_いたばし防災＋フェア']);
        $c = $this->p('P-3', ['CT-100'], ['AIワークショップ']);
        $d = $this->p('P-2026-0298', ['CT-172'], ['前日設営'], ['date_type' => '予備日']);
        $e = $this->p('P-5', ['CT-054'], ['ちがう名前']);
        $staff = PersonFactory::new()->create(['experienced_contents' => ['格付けバトル', '水合戦']]);

        $r = ContentReorg::apply();

        $asoken = (string) Content::where('content_name', 'あそ研')->value('id');
        $this->assertNotSame('', $asoken, 'あそ研が台帳に足されていない');
        $this->assertSame(['CT-031', $asoken], $a->fresh()->content_ids);
        $this->assertSame('ロケットPDCA・あそ研', $a->fresh()->project_name);

        $this->assertSame(['CT-025'], $b->fresh()->content_ids, '区分は防災ヒーロー');
        $this->assertSame('板橋区役所_いたばし防災＋フェア', $b->fresh()->project_name, '案件名はそのまま');
        $this->assertSame(['板橋区役所_いたばし防災＋フェア'], $b->fresh()->content_names);

        $this->assertSame([], $c->fresh()->content_ids, '単発にする＝台帳から外す');
        $this->assertSame(['AIワークショップ'], $c->fresh()->content_names, '名前は残る');

        $this->assertSame(['CT-025'], $d->fresh()->content_ids);
        $this->assertSame('前日設営', $d->fresh()->date_type);

        $this->assertSame(['CT-054'], $e->fresh()->content_ids, '名前が案と違うものは触らない');
        $this->assertTrue(Content::whereKey('CT-054')->exists());

        $this->assertFalse(Content::whereKey('CT-110')->exists());
        $this->assertFalse(Content::whereKey('CT-155')->exists());
        $this->assertSame(1, ContentRoleRequirement::where('content_id', 'CT-045')->count(), '必要人数は格付けへ移る');
        $this->assertSame(['格付け', '水合戦'], $staff->fresh()->experienced_contents, '名簿の経験も付け替える');

        $this->assertSame(5, Project::count(), '案件は消えない');
        $this->assertGreaterThan(0, $r['done']);

        // 二度目は何も起きない。
        $again = ContentReorg::apply();
        $this->assertSame(0, $again['done']);
    }

    public function test_画面はAdministratorだけ(): void
    {
        $admin = PersonFactory::new()->create(['permission' => 'admin', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($admin)->get('/masters/content-reorg')->assertOk()->assertSee('コンテンツ台帳の整理');

        $mgr = PersonFactory::new()->create(['permission' => 'manager', 'office' => '東京', 'must_onboard' => false]);
        $this->actingAsPerson($mgr)->post('/masters/content-reorg');
        $this->assertFalse(Content::where('content_name', 'あそ研')->exists(), '管理者は実行できない');
    }
}
