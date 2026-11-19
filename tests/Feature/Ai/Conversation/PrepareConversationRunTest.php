<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\PrepareConversationRun;
use App\Actions\Ai\Conversation\SdkTool;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use LogicException;
use Tests\Feature\Ai\ArrangesToolJudgements;
use Tests\Support\TransactionalTestCase;

/**
 * 会話のジョブの 3 の渡す道具(docs の 設計/AI/エージェントの実行/README.md 9.1、決め方 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3)と、
 * 道具を SDK の道具の約束の形に写すこと(設計/AI/会話の中核.md 13.1)。
 */
final class PrepareConversationRunTest extends TransactionalTestCase
{
    use ArrangesToolJudgements;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeJudgements();
    }

    public function test_the_passed_tools_are_recorded(): void
    {
        $definition = AiAgentDefinition::query()->findOrFail($this->run->agent_definition_id);
        $granted = app(PrepareConversationRun::class)->execute($this->run, $definition);

        $this->assertNotSame([], $granted->names());
        $this->assertSame($granted->names(), AiRun::query()->findOrFail($this->run->id)->passed_tools);
    }

    /** SDK の道具は渡す道具の名前・説明・引数の形を持ち、SDK から動かせない */
    public function test_the_sdk_tool_only_describes(): void
    {
        $granted = $this->granted->tools[0];
        $tool = new SdkTool($granted);

        $this->assertSame($granted->tool->name(), ToolNameResolver::resolve($tool));
        $this->assertSame($granted->tool->description($granted->scope), $tool->description());
        $this->assertNotSame([], $tool->schema(new JsonSchemaTypeFactory));
        $this->expectException(LogicException::class);
        $tool->handle(new Request([]));
    }
}
