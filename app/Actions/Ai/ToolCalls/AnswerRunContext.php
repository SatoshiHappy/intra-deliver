<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationStore;
use App\Models\Ai\AiRun;

/**
 * 書く時の部品が答えの実行の承認した件を通すのに要る物(答えの実行・答えの実行の始めに渡した道具・会話の保存の口)。承認した件はステップの前に
 * 動かし、前の実行の行を使うので、ステップを持たない({@see ToolStep} と型を分けるのは、行を作る経路にステップの無い物を渡せないようにするため)。
 */
final readonly class AnswerRunContext
{
    public function __construct(
        public AiRun $run,
        public GrantedTools $tools,
        public ConversationStore $store,
    ) {}
}
