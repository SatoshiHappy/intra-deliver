<?php

namespace Database\Factories\LayerFixture;

use App\Enums\Alpha\AlphaStatus;
use App\Models\Alpha\AlphaItem;
use App\Support\SupportValue;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * database/factories → 永続化・列挙・共有・外部(require-dev のパッケージを含む)
 *
 * @extends Factory<AlphaItem>
 */
final class AlphaItemFactory extends Factory
{
    protected $model = AlphaItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['status' => AlphaStatus::Open];
    }

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesGenerator(Generator $generator): Generator
    {
        return $generator;
    }
}
