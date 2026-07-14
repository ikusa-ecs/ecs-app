<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の people（利用者）を1行で作るための雛型。
 * 既定は「社員（employee）・初回設定済み・在籍中」＝業務画面にそのまま入れる人。
 *
 * 使い方の例：
 *   PersonFactory::new()->create();                 // 社員
 *   PersonFactory::new()->staff()->create();        // スタッフ
 *   PersonFactory::new()->manager()->create();      // 管理者
 *   PersonFactory::new()->admin()->create();        // Administrator
 *
 * ※ モデル側に HasFactory を付けていないため、テストでは Person::factory() ではなく
 *    PersonFactory::new() の形で呼ぶ（既存モデルに手を入れない方針のため）。
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            // ID は E-001 / S-001 のような文字列。テストでは重複しなければよいので連番風に発番。
            'id'           => 'E-' . $this->faker->unique()->numerify('#####'),
            'role'         => 'employee',
            'name'         => $this->faker->name(),
            'email'        => $this->faker->unique()->safeEmail(),
            'password'     => 'password',   // モデルの 'hashed' キャストで保存時に自動暗号化される
            'permission'   => 'employee',   // 権限4段階：staff/employee/manager/admin
            'active'       => true,
            'must_onboard' => false,        // 初回設定は済み（true だと /onboarding に戻される）
        ];
    }

    /** スタッフ（現場スタッフ）。業務画面には入れず、自分のスタッフ画面に戻される権限。 */
    public function staff(): static
    {
        return $this->state(fn () => [
            'id'         => 'S-' . $this->faker->unique()->numerify('#####'),
            'role'       => 'staff',
            'permission' => 'staff',
        ]);
    }

    /** 管理者（作成・案件削除などができる）。 */
    public function manager(): static
    {
        return $this->state(fn () => ['permission' => 'manager']);
    }

    /** Administrator（マスタ削除・権限付与などの最上位）。 */
    public function admin(): static
    {
        return $this->state(fn () => [
            'permission' => 'admin',
            'is_admin'   => true,
        ]);
    }

    /** 初回設定がまだの人（/onboarding へ誘導されるはず）。 */
    public function needsOnboarding(): static
    {
        return $this->state(fn () => ['must_onboard' => true]);
    }
}
