<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\ToolCalls\ToolPartyNames;
use App\Enums\Ai\AiToolPartyForm;
use App\Models\Ai\AiRun;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\Tools\Generic\GenericTool;
use Tests\TestCase;

/**
 * 名前の引き当ての部品は、入れ物の入力の形の相手を答えた道具を、引数によらず同じ例外で止める(設定の確かめを経ずに登録された道具の
 * 定義の誤り。docs の 設計/AI/道具と権限/README.md 10.1 の相手を含む引数)。
 */
final class ToolPartyNamesTest extends TestCase
{
    /**
     * 引数(業務モジュールが無い・範囲の外)。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function arguments(): array
    {
        return ['業務モジュールが無い' => [[]], '範囲の外の業務モジュール' => [['module' => 'm_outside']]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('arguments')]
    public function test_an_input_form_is_stopped_whatever_the_arguments(array $arguments): void
    {
        $tool = new GenericTool('ai_r', parties: ['module' => AiToolPartyForm::Input]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('道具 ai_r の入れ物の入力の形の相手は引けない');

        app(ToolPartyNames::class)->resolve($tool, $arguments, new AgentToolScope, new AiRun);
    }
}
