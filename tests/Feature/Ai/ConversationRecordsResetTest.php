<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiToolCallAnswer;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reset\EnablesDailyResetBeforeBoot;
use Tests\Feature\Reset\PreparesReset;
use Tests\Feature\Reset\RunsCommands;
use Tests\Support\TransactionalTestCase;

/**
 * 公開デモの毎日の初期化は、ステップと道具の行と原文がある DB でも止まらずに 4 つの表を空にする(docs の 設計/公開の配備/毎日の初期化.md 1 節。
 * 全表を 1 つの TRUNCATE で空にするので、子の表どうしと実行を指す外部キーで止まらない)。
 */
final class ConversationRecordsResetTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    private const TABLES = ['ai_run_steps', 'ai_run_step_texts', 'ai_tool_calls', 'ai_tool_call_texts'];

    public function test_the_daily_reset_empties_the_step_and_tool_call_tables(): void
    {
        $this->prepareReset();
        $step = AiRunStep::factory()->withText()->create();
        $call = AiToolCall::factory()->inStep($step)->answered(AiToolCallAnswer::NextText)->withText()->create();
        AiRunStep::factory()->insideToolCall($call)->withText()->create();
        $this->assertSame([2, 2, 1, 1], $this->counts());

        $this->assertCommand('reset:data', 0, '成否: 成功');

        $this->assertSame([0, 0, 0, 0], $this->counts());
    }

    /**
     * 4 つの表の行の数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), self::TABLES);
    }
}
