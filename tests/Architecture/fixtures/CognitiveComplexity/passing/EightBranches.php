<?php

namespace Fixture\CognitiveComplexity;

/**
 * 分岐 8 個(入れ子なし)= 認知的複雑度 8。
 */
final class EightBranches
{
    public function score(int $value): int
    {
        $score = 0;

        if ($value > 1) {
            $score++;
        }

        if ($value > 2) {
            $score++;
        }

        if ($value > 3) {
            $score++;
        }

        if ($value > 4) {
            $score++;
        }

        if ($value > 5) {
            $score++;
        }

        if ($value > 6) {
            $score++;
        }

        if ($value > 7) {
            $score++;
        }

        if ($value > 8) {
            $score++;
        }

        return $score;
    }
}
