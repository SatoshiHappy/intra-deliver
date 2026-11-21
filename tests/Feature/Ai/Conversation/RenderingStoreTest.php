<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Conversation\RenderingStore;
use App\Actions\Ai\Core\StoredTexts;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Enums\Ai\AiInstructionTemplate;
use Tests\TestCase;

/**
 * 会話の保存に書く時の組んだ文(docs の 設計/AI/会話の中核.md 13.2): 道具の結果と中断の印は、指示の組み立ての口の今の版の文と一覧で組んだ
 * 文と一覧の印を持って書かれ、ほかの項目と読みはそのまま。足せたかは包んだ口の返り値のまま。
 */
final class RenderingStoreTest extends TestCase
{
    public function test_tool_results_and_interruptions_are_written_with_their_rendered_texts(): void
    {
        $inner = new class implements ConversationStore
        {
            /** @var list<ConversationItem> */
            public array $written = [];

            /** @return list<ConversationItem> */
            public function read(int $runId): array
            {
                return $this->written;
            }

            public function append(int $runId, array $items): bool
            {
                array_push($this->written, ...$items);

                return false;
            }
        };
        $builder = app(InstructionBuilder::class);
        $store = new RenderingStore($inner, $builder);
        $user = ConversationItem::userText('問い');
        $unknown = AiInstructionTemplate::UnknownTool;
        $result = ConversationItem::toolResult('c1', $unknown, ['tool_name' => 'x'], null, true);

        $appended = $store->append(1, [$user, $result, ConversationItem::interruption(true)]);

        $id = StoredTexts::formsId($builder->conversationMarkerForms());
        $this->assertFalse($appended);
        $this->assertSame($user, $inner->written[0]);
        $this->assertSame([
            'text' => $builder->template(AiInstructionTemplate::UnknownTool, ['tool_name' => 'x']),
            'forms_id' => $id,
        ], $inner->written[1]->content['rendered']);
        $this->assertSame([
            'text' => $builder->template(AiInstructionTemplate::InterruptedForToolUse, []),
            'forms_id' => $id,
        ], $inner->written[2]->content['rendered']);
        $this->assertSame($inner->written, $store->read(1));
    }
}
