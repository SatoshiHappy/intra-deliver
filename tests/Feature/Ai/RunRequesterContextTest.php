<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\RunRequesterContext;
use App\Models\Ai\AiRun;
use Tests\Support\TransactionalTestCase;

/**
 * worker の中の依頼者の文脈(docs の 設計/AI/公開の口.md 3.2 の「依頼者の文脈」、データと失敗と検査.md 8 節の「2 段目の訪問者の ID」):
 * 実行の記録の訪問者の ID を写す(無ければ空)。2 段目の依頼はこの文脈で依頼の口を呼ぶので、新しい実行に同じ ID が書かれる。
 */
final class RunRequesterContextTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    private const VISITOR_ID = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
    }

    public function test_the_context_has_the_visitor_id_of_the_run(): void
    {
        $context = $this->requesterContext(visitorId: self::VISITOR_ID);
        $run = AiRun::query()->findOrFail($this->queuedRun(context: $context));

        $this->assertSame(self::VISITOR_ID, app(RunRequesterContext::class)->of($run)->visitorId);
    }

    public function test_the_context_of_a_run_without_a_visitor_id_has_none(): void
    {
        $run = AiRun::query()->findOrFail($this->queuedRun());

        $this->assertNull(app(RunRequesterContext::class)->of($run)->visitorId);
    }
}
