<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\BuiltInstructions;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionRequest;
use App\Actions\Ai\Instructions\TitleField;
use App\Enums\Ai\AiInstructionTemplate;
use Closure;

/**
 * 会話の印の形の一覧だけを替えた版(テストの中だけ。文は元の版のまま)。一覧は、元の一覧と組み立てを済ませたかを受ける関数で決める。
 */
final class AlteredMarkerFormsBuilder implements InstructionBuilder
{
    private bool $built = false;

    /**
     * @param  Closure(list<string>, bool): list<string>  $forms
     */
    public function __construct(private InstructionBuilder $inner, private Closure $forms) {}

    public function build(InstructionRequest $request): BuiltInstructions
    {
        $this->built = true;

        return $this->inner->build($request);
    }

    public function template(AiInstructionTemplate $template, array $values): string
    {
        return $this->inner->template($template, $values);
    }

    public function compactSummary(string $answer): string
    {
        return $this->inner->compactSummary($answer);
    }

    public function titleField(): TitleField
    {
        return $this->inner->titleField();
    }

    public function conversationMarkerForms(): array
    {
        return ($this->forms)($this->inner->conversationMarkerForms(), $this->built);
    }

    public function problems(): array
    {
        return $this->inner->problems();
    }
}
