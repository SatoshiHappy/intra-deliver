<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ExecutePrompt;
use App\Support\AccessContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\TransactionalTestCase;

/**
 * 接続先へ送る訪問者の ID(docs の 設計/AI/実行と費用.md 4.4、データと失敗と検査.md 8 節の「訪問者の ID の送り方」): driver が anthropic の
 * 実行で ID があれば要求の本文に metadata.user_id、無ければ metadata が無い。driver が anthropic でない接続先には ID があっても送らない。
 * 本文は接続先の HTTP を Http::fake に差し替えて、実際に送った要求で確かめる。
 */
final class VisitorIdToProviderTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    private const VISITOR_ID = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

    private const ANTHROPIC_MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    private const OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
        config(['ai.providers.openai.key' => 'fake-key-for-tests']);
    }

    public function test_anthropic_with_a_visitor_id_sends_it_as_the_metadata_user_id(): void
    {
        Http::fake([self::ANTHROPIC_MESSAGES_URL => Http::response($this->anthropicMessage())]);

        app(ExecutePrompt::class)->execute($this->queuedRun(context: $this->visitorContext()));

        Http::assertSent(fn (Request $request): bool => $request['metadata'] === ['user_id' => self::VISITOR_ID]
            && $request['output_config']['effort'] === 'low');
    }

    public function test_anthropic_without_a_visitor_id_sends_no_metadata(): void
    {
        Http::fake([self::ANTHROPIC_MESSAGES_URL => Http::response($this->anthropicMessage())]);

        app(ExecutePrompt::class)->execute($this->queuedRun());

        $this->assertSentWithoutMetadata(self::ANTHROPIC_MESSAGES_URL);
    }

    /**
     * 接続先が driver openai の種類(応答は誤りにして、送った本文だけを見る)。
     */
    public function test_a_provider_that_is_not_anthropic_gets_no_metadata_even_with_a_visitor_id(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'provider', 'openai');
        $this->setKindSetting(RecordingPromptKind::CODE, 'model', 'gpt-test');
        $this->setKindSetting(RecordingPromptKind::CODE, 'provider_options', []);
        $price = ['input' => 1.0, 'output' => 1.0, 'cache_read' => 1.0, 'cache_write' => 1.0];
        config(['ai_runs.pricing.openai.gpt-test' => [['up_to_input_tokens' => null, ...$price]]]);
        Http::fake([self::OPENAI_RESPONSES_URL => Http::response(['error' => ['message' => '接続先の誤り']], 500)]);

        $this->executeFailing($this->queuedRun(context: $this->visitorContext()));

        $this->assertSentWithoutMetadata(self::OPENAI_RESPONSES_URL);
        Http::assertSentCount(1);
    }

    private function visitorContext(): AccessContext
    {
        return $this->requesterContext(visitorId: self::VISITOR_ID);
    }

    private function assertSentWithoutMetadata(string $url): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && ! array_key_exists('metadata', $request->data()));
    }

    /**
     * Anthropic の Messages API の成功の応答(構造化出力の 1 項目)。
     *
     * @return array<string, mixed>
     */
    private function anthropicMessage(): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => '{"summary":"要約"}']],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 30,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }
}
