<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReading;
use App\Actions\Ai\Core\TextDeltaNotice;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 要約の送信の受け先: 確定した文を集め、呼び出しは捨てる(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。要約の応答は
 * 要約の境目と要約の文として書くので、モデルの文と応答の終わりは書かない(設計/AI/会話の中核の口と受け手.md 13.3)。
 */
final class CompactionAnswerSink implements SubstituteSink
{
    /** @var list<string> */
    private array $texts = [];

    public function __construct(private readonly CoreNotifier $notifier) {}

    public function confirmed(string $responseId, int $stepId, array $blocks): void
    {
        foreach ($blocks as $block) {
            if ($block['type'] === 'text') {
                $this->texts[] = $block['text'];
            }
        }
    }

    public function delta(int $stepId, string $text): void
    {
        $this->notifier->notify(new TextDeltaNotice($stepId, $text));
    }

    public function responded(string $responseId, StepResponse $response, SubstituteReading $reading): void {}

    /** 要約の答え(空なら文が無い) */
    public function answer(): string
    {
        return trim(implode("\n", $this->texts));
    }
}
