<?php

namespace App\Actions\Ai\Core\Substitute;

/**
 * 代わりの版の送る形のメッセージ 1 つ(利用者の文かモデルの文)。モデルの文は 1 つの応答の確定したブロックを束ねる
 * (docs の 設計/AI/会話の中核.md 13.2 の「送るときに同じ応答の id の項目を 1 つのモデルの文に写す」)。
 *
 * @phpstan-type SentBlock array{type: 'text', text: string}
 *     |array{type: 'tool_call', id: string, name: string, arguments: array<string, mixed>, result_id: string|null}
 *     |array{type: 'tool_result', call_id: string, text: string, is_error: bool}
 */
final readonly class SentMessage
{
    /**
     * @param  list<SentBlock>  $blocks
     * @param  array<array-key, mixed>|null  $replay  渡す生の状態(渡すと決めた応答だけ)
     */
    public function __construct(
        public bool $fromModel,
        public array $blocks,
        public ?string $responseId = null,
        public ?array $replay = null,
    ) {}

    public static function user(string $text): self
    {
        return new self(false, [['type' => 'text', 'text' => $text]]);
    }

    public static function model(string $text): self
    {
        return new self(true, [['type' => 'text', 'text' => $text]]);
    }

    /**
     * @param  list<SentBlock>  $blocks
     */
    public function withBlocks(array $blocks): self
    {
        return new self($this->fromModel, $blocks, $this->responseId, $this->replay);
    }

    /**
     * @param  array<array-key, mixed>|null  $replay
     */
    public function withReplay(?array $replay): self
    {
        return new self($this->fromModel, $this->blocks, $this->responseId, $replay);
    }

    /**
     * 道具の呼び出しの id の並び。
     *
     * @return list<string>
     */
    public function callIds(): array
    {
        $ids = [];

        foreach ($this->blocks as $block) {
            if ($block['type'] === 'tool_call') {
                $ids[] = $block['id'];
            }
        }

        return $ids;
    }
}
