<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use Closure;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * 会話の印の形の一覧の約束のテスト({@see InstructionMarkerContract})が、印を 1 つ欠いた一覧を返す版と、組み立ての後で一覧が変わる版を落とす
 * (docs の 設計/AI/設定とプロンプト/README.md 11.4 の約束のテスト)。替えない版では通る(このクラスでも約束のテストが流れる)。
 */
final class InstructionMarkerContractSelfTest extends TestCase
{
    use InstructionMarkerContract;

    private ?InstructionBuilder $builder = null;

    protected function instructionBuilder(): InstructionBuilder
    {
        return $this->builder ?? new DefaultInstructionBuilder;
    }

    protected function conversationMarkerPattern(): string
    {
        return '/^\[[^\[\]\r\n]+\]$/mu';
    }

    public function test_a_list_missing_one_form_fails(): void
    {
        $this->assertContractFails(fn (array $forms): array => array_slice($forms, 1));
    }

    public function test_a_list_that_changes_after_building_fails(): void
    {
        $this->assertContractFails(fn (array $forms, bool $built): array => $built ? [...$forms, '[added]'] : $forms);
    }

    /**
     * @param  Closure(list<string>, bool): list<string>  $forms
     */
    private function assertContractFails(Closure $forms): void
    {
        $this->builder = new AlteredMarkerFormsBuilder(new DefaultInstructionBuilder, $forms);

        try {
            $this->test_the_marker_forms_include_every_marker_in_the_built_texts_and_stay_the_same();
        } catch (AssertionFailedError) {
            $this->builder = null;

            return;
        }

        $this->fail('約束のテストが替えた一覧の版を落とさなかった');
    }
}
