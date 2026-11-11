<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\BuiltInstructions;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\Instructions\TitleField;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;

/**
 * 起動の時の確かめで決めた食い違いの文を返す版(手元だけのリポジトリの移した版に見立てる。確かめのほかは使わない)。
 */
final class ProblemInstructionBuilder implements InstructionBuilder
{
    public const PROBLEM = '指示の文 conversation_system(結んだ版)に許す一覧に無い置き換え口 {{unknown}} がある';

    /**
     * @param  list<string>  $problems
     */
    public function __construct(private array $problems = [self::PROBLEM]) {}

    public function build(InstructionRequest $request): BuiltInstructions
    {
        throw new LogicException('使わない');
    }

    public function template(AiInstructionTemplate $template, array $values): string
    {
        throw new LogicException('使わない');
    }

    public function compactSummary(string $answer): string
    {
        throw new LogicException('使わない');
    }

    public function titleField(): TitleField
    {
        throw new LogicException('使わない');
    }

    public function problems(): array
    {
        return $this->problems;
    }
}
