<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Support\ClientName;
use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * クライアント名の書き方そろえ（2026-08-21 baba）。
 *
 * 「〇〇株式会社」と「〇〇株式会社様」が別のお客様として数えられると、
 * リピート判定・過去のアサイン履歴が分かれてしまう。保存時に敬称と空白を落としてそろえる。
 */
class ClientNameNormalizeTest extends TestCase
{
    use RefreshDatabase;

    /** 敬称と前後の空白を落とす。 */
    public function test_normalize_strips_honorifics_and_spaces(): void
    {
        $this->assertSame('〇〇株式会社', ClientName::normalize('〇〇株式会社様'));
        $this->assertSame('〇〇株式会社', ClientName::normalize('〇〇株式会社 様'));
        $this->assertSame('〇〇株式会社', ClientName::normalize('　〇〇株式会社　様　'));
        $this->assertSame('〇〇株式会社', ClientName::normalize('〇〇株式会社御中'));
        $this->assertSame('〇〇株式会社', ClientName::normalize('〇〇株式会社'));
        $this->assertNull(ClientName::normalize('   '));
        $this->assertNull(ClientName::normalize(null));
    }

    /** 「様」を付けて登録しても、保存されるのは社名だけ。 */
    public function test_project_is_saved_without_the_honorific(): void
    {
        $employee = PersonFactory::new()->create();

        $this->actingAsPerson($employee)->post('/project-form', [
            'content_names'  => '水合戦',
            'client'         => '〇〇株式会社 様',
            'start_date'     => '2026-09-01',
            'required_count' => '10',
            'intent'         => 'publish',
        ]);

        $this->assertSame('〇〇株式会社', Project::where('project_name', '水合戦')->first()->client);
    }
}
