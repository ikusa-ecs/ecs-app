<?php

namespace Tests\Feature;

use Database\Factories\PersonFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 「マイグレーションを流さずにデプロイしても画面が壊れないこと」を見張る（2026-08-31）。
 *
 * 【なぜ要るか＝実際に起きた】
 * デプロイは `git pull` → `composer install` → `php artisan migrate` → `optimize` の順だが、
 * **migrate を飛ばしても画面は普通に開いてしまう**ので、その場では気づけない。
 * 新しい列を読むところが増えていると、そこだけ静かに壊れる。
 *
 * ここでは「新しく足した列がまだ無いサーバー」を作って、主な画面が開くか確かめる。
 * ⚠ 列が無くても**落ちない**のが正しい姿。列があることを前提に書くと、
 *   デプロイの順番を1つ間違えただけで画面が死ぬ。
 */
class DeployWithoutMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-08-31 に足した列（マイグレーション前のサーバーを再現するために外す）。 */
    private const NEW_COLUMNS = [
        'other_languages',
        'challenge_positions',
        'online_tools',
        'online_tools_other',
        'profile_note',
    ];

    private function dropNewColumns(): void
    {
        Schema::table('people', function ($table) {
            $table->dropColumn(self::NEW_COLUMNS);
        });
    }

    /** スタッフ画面が開く（列がまだ無くても）。 */
    public function test_the_staff_portal_still_works_without_the_new_columns(): void
    {
        $staff = PersonFactory::new()->staff()->create();
        $this->dropNewColumns();

        $this->actingAsPerson($staff)->get('/staff-portal')->assertOk();
    }

    /** マイプロフィールが開く（列がまだ無くても）。 */
    public function test_the_profile_page_still_works_without_the_new_columns(): void
    {
        $me = PersonFactory::new()->create();
        $this->dropNewColumns();

        $this->actingAsPerson($me)->get('/profile')->assertOk();
    }

    /** 名簿が開く（列がまだ無くても）。 */
    public function test_the_rosters_still_work_without_the_new_columns(): void
    {
        $admin = PersonFactory::new()->admin()->create();
        PersonFactory::new()->staff()->create();
        $this->dropNewColumns();

        $this->actingAsPerson($admin)->get('/staff')->assertOk();
        $this->actingAsPerson($admin)->get('/employees')->assertOk();
    }
}
