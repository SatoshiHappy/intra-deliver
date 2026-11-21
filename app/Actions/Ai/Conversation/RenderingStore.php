<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Core\StoredTexts;
use App\Actions\Ai\Instructions\InstructionBuilder;

/**
 * 会話の保存に書く時に、道具の結果と中断の印に組んだ文と会話の印の形の一覧の印を持たせる(docs の 設計/AI/会話の中核.md 13.2、
 * 設計/AI/エージェントの実行/口と契約.md 9.13)。③ が会話の保存に書くどの道(ループの知らせ・包み・答えの実行の始め・止める)も、種類の
 * 会話の保存の口をこれで包んで書く(組む部品は中核の約束の部品 1 つ = {@see StoredTexts})。読みはそのまま。
 */
final readonly class RenderingStore implements ConversationStore
{
    private StoredTexts $texts;

    public function __construct(private ConversationStore $inner, InstructionBuilder $builder)
    {
        $this->texts = new StoredTexts($builder->template(...), $builder->conversationMarkerForms());
    }

    public function read(int $runId): ?array
    {
        return $this->inner->read($runId);
    }

    public function append(int $runId, array $items): bool
    {
        return $this->inner->append($runId, array_map($this->texts->withRendered(...), $items));
    }
}
