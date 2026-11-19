<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\LoopRequestOf;
use App\Actions\Ai\Conversation\RunCreatedFiles;
use App\Actions\Ai\Conversation\StartConversationRun;
use App\Actions\Ai\Conversation\StepTargetsOf;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Models\Ai\AiRun;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeClock;
use Tests\Unit\Actions\Ai\Core\Support\FakeLoopTexts;
use Tests\Unit\Actions\Ai\Core\Support\FakeReceivers;
use Tests\Unit\Actions\Ai\Core\Support\FakeTools;

/**
 * 会話のジョブの 5 で中核に渡す物(docs の 設計/AI/エージェントの実行/README.md 9.1 の口の約束、写した値 = 設計/AI/実行の記録.md 2.1)。
 * 種類の設定 = 回数の上限 10・会話の用途の時間の上限 100 秒・出力の上限 1,000。
 */
final class LoopRequestOfTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private const VISITOR = '0193c7a2-1b2c-7d3e-8f40-123456789abc';

    public function test_the_request_uses_the_copied_values(): void
    {
        $this->registerTestConversationKind();
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'visitor_id' => self::VISITOR,
        ]);
        app(StartConversationRun::class)->execute($run->id);
        $receivers = new FakeReceivers;
        $loopReceivers = new LoopReceivers(
            new FakeTools,
            $receivers,
            $receivers,
            new FakeClock,
            new FakeLoopTexts,
            new RunCreatedFiles,
        );

        $request = app(LoopRequestOf::class)->of(
            AiRun::query()->findOrFail($run->id),
            app(StepTargetsOf::class)->targets(AiRun::query()->findOrFail($run->id))->conversation,
            [],
            new GrantedTools([], []),
            new AddedTextValues('2026-11-19'),
            $loopReceivers,
        );

        $model = $request->target->model;
        $this->assertSame(['claude-sonnet-5-5', 1000000, 1000, true], [
            $model->id,
            $model->contextWindow,
            $model->maxOutputTokens,
            $model->usesThinking,
        ]);
        $this->assertSame([100, 10, true], [$request->target->timeoutSeconds, $request->maxTurns, $request->stream]);
        $metadata = $request->providerOptions['turn']['metadata'] ?? null;
        $this->assertSame(['user_id' => self::VISITOR], $metadata);
        $this->assertSame($request->providerOptions['turn'], $request->providerOptions['compaction']);
        $this->assertSame([], $request->tools);
    }
}
