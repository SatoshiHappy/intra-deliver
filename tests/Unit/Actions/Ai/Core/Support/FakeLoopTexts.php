<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use App\Actions\Ai\Core\ComposedInstructions;
use App\Actions\Ai\Core\LoopTexts;
use App\Actions\Ai\Core\SendKind;
use App\Enums\Ai\AiInstructionTemplate;

/**
 * 中核のテストの文の口(定型の種類と値を見分けの文にする。版の文に依らない)。
 */
final class FakeLoopTexts implements LoopTexts
{
    public function instructions(SendKind $kind, array $toolNames, string $date): ComposedInstructions
    {
        $compaction = $kind === SendKind::Compaction || $kind === SendKind::CompactionFallback;
        $atEnd = $compaction ? ["summarize:{$kind->value}"] : [];

        return new ComposedInstructions("system:{$kind->value}", ["date:{$date}"], $atEnd);
    }

    public function template(AiInstructionTemplate $template, array $values): string
    {
        $parts = array_map(
            static fn (string $key, string|array $value): string => $key.'='
                .(is_array($value) ? implode('|', $value) : $value),
            array_keys($values),
            $values,
        );

        return '['.$template->value.($parts === [] ? '' : ' '.implode(' ', $parts)).']';
    }

    public function compactSummary(string $answer): string
    {
        return "summary:{$answer}";
    }

    public function markerForms(): array
    {
        return ['<mark>'];
    }
}
