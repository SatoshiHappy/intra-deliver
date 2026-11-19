<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\ConversationTitle;
use App\Actions\Ai\Conversation\StartConversationRun;
use App\Actions\Ai\Conversation\StepTargetsOf;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendFailed;
use App\Actions\Ai\Core\SendFailure;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Core\SendResult;
use App\Actions\Ai\Core\SentResponse;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Ai\Conversation\Support\ScriptedCore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeClock;
use Tests\Unit\Actions\Ai\Core\Support\FakeLoopTexts;
use Tests\Unit\Actions\Ai\Core\Support\FakeReceivers;

/**
 * 会話のジョブの 2 の題のステップ(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.10、題の欄 = 設計/AI/会話の中核.md 13.6)。
 */
final class ConversationTitleTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private AiRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        $definition = AiAgentDefinition::factory()->create();
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'agent_definition_id' => $definition->id,
        ]);
        app(StartConversationRun::class)->execute($run->id);
        $this->run = AiRun::query()->findOrFail($run->id);
    }

    public function test_a_title_is_sent_once_to_the_title_purpose(): void
    {
        $core = new ScriptedCore(self::sent(ResponseFinish::Stop, ['title' => '題']));

        $title = $this->make($core, '会議の要約を作って');

        $this->assertSame('題', $title);
        $this->assertCount(1, $core->sent);
        $request = $core->sent[0];
        $target = app(StepTargetsOf::class)->targets($this->run)->title;
        $sentTo = [$request->target->providerName, $request->target->model->id];
        $this->assertSame([$target->provider, $target->model], $sentTo);
        $this->assertSame([], $request->tools);
        $this->assertNotNull($request->schema);
        $this->assertCount(1, $request->history);
    }

    /**
     * @return array<string, array{SendResult}>
     */
    public static function noTitles(): array
    {
        return [
            '止まらずに長さで終わった' => [self::sent(ResponseFinish::Length, ['title' => '題'])],
            '構造化の出力が無い' => [self::sent(ResponseFinish::Stop, null)],
            '題の欄が文字列でない' => [self::sent(ResponseFinish::Stop, ['title' => 1])],
        ];
    }

    #[DataProvider('noTitles')]
    public function test_no_title_without_a_stopped_string(SendResult $result): void
    {
        $this->assertNull($this->make(new ScriptedCore($result), '文'));
    }

    /** 送信の失敗は報告して題なしで続ける(握りつぶさない) */
    public function test_a_failed_send_is_reported(): void
    {
        Exceptions::fake();

        $title = $this->make(new ScriptedCore(new SendFailed(SendFailure::Timeout)), '文');

        $this->assertNull($title);
        Exceptions::assertReported(RuntimeException::class);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blanks(): array
    {
        return ['半角の空白と改行' => [" \n "], '全角の空白' => ["\u{3000}\u{3000}"], '空' => ['']];
    }

    /** 前後の空白を除いて空の利用者の文では送らない(全角の空白も空白) */
    #[DataProvider('blanks')]
    public function test_a_blank_user_text_is_not_sent(string $text): void
    {
        $core = new ScriptedCore;

        $this->assertNull($this->make($core, $text));
        $this->assertSame([], $core->sent);
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private static function sent(ResponseFinish $finish, ?array $structured): SentResponse
    {
        $usage = [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ];

        return new SentResponse([], $finish, $usage, $structured);
    }

    private function make(ScriptedCore $core, string $text): ?string
    {
        $receivers = new FakeReceivers;

        return app(ConversationTitle::class)->make(
            $core,
            $this->run,
            AiAgentDefinition::query()->findOrFail($this->run->agent_definition_id),
            ConversationItem::userText($text),
            app(StepTargetsOf::class)->targets($this->run)->title,
            new SendReceivers($receivers, $receivers, new FakeClock, new FakeLoopTexts),
        );
    }
}
