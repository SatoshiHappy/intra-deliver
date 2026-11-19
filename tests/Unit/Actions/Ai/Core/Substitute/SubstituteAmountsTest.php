<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\Substitute\SubstituteAmounts;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\EmptyContainerTestCase;

/**
 * 代わりの版の量の式(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9 の「量」): 止める量 = 読める量 − 出力の上限、
 * 発動の量 = 止める量の 8 割。止める量が 0 以下(読める量が出力の上限以下)と読める量が無いときは作りの誤り。
 */
final class SubstituteAmountsTest extends EmptyContainerTestCase
{
    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function amounts(): array
    {
        return [
            'ふつうの量' => [40000, 1000, 31200, 39000],
            '止める量が 1(境の 1 つ上)' => [1001, 1000, 0, 1],
            '割り切れない 8 割は切り捨て' => [1007, 1000, 5, 7],
        ];
    }

    #[DataProvider('amounts')]
    public function test_thresholds(int $window, int $maxOutput, int $compactAt, int $stopAt): void
    {
        $thresholds = SubstituteAmounts::of(new ModelSpec('m', $window, $maxOutput, null, false));

        $this->assertSame([$compactAt, $stopAt], [$thresholds->compactAt, $thresholds->stopAt]);
    }

    /**
     * @return array<string, array{int|null, int}>
     */
    public static function impossible(): array
    {
        return [
            '読める量が出力の上限と同じ(境)' => [1000, 1000],
            '読める量が出力の上限より小さい' => [999, 1000],
            '読める量が無い' => [null, 1000],
        ];
    }

    #[DataProvider('impossible')]
    public function test_impossible_amounts_are_construction_errors(?int $window, int $maxOutput): void
    {
        $this->expectException(LogicException::class);

        SubstituteAmounts::of(new ModelSpec('m', $window, $maxOutput, null, false));
    }

    public function test_the_estimate_rounds_up(): void
    {
        $this->assertSame([10, 11, 0], [
            SubstituteAmounts::estimate(10, 1.0),
            SubstituteAmounts::estimate(10, 1.05),
            SubstituteAmounts::estimate(0, 1.5),
        ]);
    }
}
