<?php

namespace Database\Factories;

use App\Models\Content;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の contents（コンテンツ・マスタ）を1行で作る雛型。
 * 例：ContentFactory::new()->create(['content_name' => '水合戦']);
 */
class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        return [
            'id'           => 'CT-' . $this->faker->unique()->numerify('####'),
            'content_name' => $this->faker->unique()->words(2, true),
            'category'     => null,
            'is_physical'  => false,
            'active'       => true,
        ];
    }
}
