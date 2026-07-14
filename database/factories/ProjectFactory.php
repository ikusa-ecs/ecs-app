<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の projects（案件）を1行で作る雛型。
 * 既定は「本番・非公開・未着手」の最小限の案件。必要な列は create() で上書きする。
 *
 * 例：
 *   ProjectFactory::new()->create(['start_date' => '2026-09-01']);
 *   ProjectFactory::new()->published()->create();   // スタッフ公開済み
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'id'              => 'P-2026-' . $this->faker->unique()->numerify('####'),
            'project_name'    => $this->faker->words(2, true),
            'content_ids'     => null,
            'start_date'      => $this->faker->dateTimeBetween('now', '+3 months')->format('Y-m-d'),
            'date_type'       => '本番',
            'required_count'  => $this->faker->numberBetween(3, 20),
            'is_recruiting'   => true,
            'staff_published' => false,
            'status'          => '未着手',
        ];
    }

    /** スタッフに公開済みの案件（スタッフ画面に出る想定）。 */
    public function published(): static
    {
        return $this->state(fn () => ['staff_published' => true]);
    }

    /** 下書き（未公開・未確定）。 */
    public function draft(): static
    {
        return $this->state(fn () => ['status' => '下書き']);
    }
}
