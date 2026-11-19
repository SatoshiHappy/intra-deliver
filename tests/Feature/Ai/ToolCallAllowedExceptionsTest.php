<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiToolCallStatus;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 許すの経路の例外の分け方(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4・設計/AI/エージェントの実行/状態の移り方.md 9.11 の
 * S23): 道具の行の結果と会話の保存の足すの例外は、道具の誤りにせず受け手から投げ、行は処理中のまま残る(後始末で結果不明)。
 */
final class ToolCallAllowedExceptionsTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->startRecordToolRun(['m_tasks']);
    }

    public function test_an_exception_while_appending_the_result_is_thrown_and_the_row_stays_running(): void
    {
        $id = $this->task(['title' => '読む']);
        $this->store->failAfterWrites = 0;

        $this->assertThrows(
            fn () => $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]),
            RuntimeException::class,
        );

        $this->assertSame(AiToolCallStatus::Running, $this->toolRow()->status);
    }
}
