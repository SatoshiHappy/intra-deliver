<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\DefinitionTooLarge;
use App\Actions\Ai\Conversation\DefinitionToolSize;
use App\Actions\Ai\Conversation\PrepareConversationRun;
use App\Actions\Ai\Conversation\SdkTool;
use App\Actions\Ai\Core\Substitute\SubstitutePlans;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiAgentDefinition;
use Carbon\CarbonImmutable;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\BuildsToolRecords;
use Tests\Feature\Ai\RunsRecordTools;
use Tests\Feature\Ai\StopsRecordModules;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 定義の道具の定義の大きさ(docs の 設計/AI/設定とプロンプト/README.md 11.3): 登録に無い道具・無い・明細・表示の止めのある業務モジュールを外して測り、
 * 上限(会話の用途のモデルの読める量の 1 割)の境目で超えるかが分かれ、実行の始めは超えれば失敗(設定)にする。
 */
final class DefinitionToolSizeTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;
    use StopsRecordModules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
    }

    /** 外す物(登録に無い道具・無い業務モジュール・明細・表示の止め)は大きさに入れない */
    public function test_removed_tools_and_modules_are_not_measured(): void
    {
        $size = app(DefinitionToolSize::class);
        $base = $size->tokens($this->definition(['ai_read_record'], ['m_tasks']));
        $this->stopModule($this->refs->id);

        $child = $this->childOf($this->taskFields['lines'])->code;

        $removed = $this->definition(['ai_read_record', 'ai_gone'], ['m_tasks', 'm_gone', 'm_refs', $child]);

        $this->assertGreaterThan(0, $base);
        $this->assertSame($base, $size->tokens($removed));
        $added = $this->definition(['ai_read_record'], ['m_tasks', 'm_refs_open']);
        $this->assertGreaterThan($base, $size->tokens($added));
    }

    /**
     * 測る道具は、権限を持つ有効な依頼者に 1 段目が渡す道具と同じ範囲と定義(明細の子の選択・参照先を含む)で、字数は送る前の見積もりと同じ
     * (どの依頼者でも上限の内にする守る結果を、本物の道具の定義で通す)
     */
    public function test_the_measured_tools_are_the_ones_the_first_stage_grants_to_a_permitted_requester(): void
    {
        $this->permit('m_tasks', [RecordModuleAction::Create, RecordModuleAction::Update]);
        $this->choiceField($this->childOf($this->taskFields['lines']), 'grade', ['a', 'b'], ['name' => '等級']);
        $tools = ['ai_search_records', 'ai_read_record', 'ai_create_record', 'ai_update_record'];
        $this->startRecordToolRun(['m_tasks'], $tools);
        $definition = $this->run->agentDefinition()->sole();
        $size = app(DefinitionToolSize::class);
        $scopes = static fn (array $granted): array => array_map(
            static fn (GrantedTool $tool): array => [$tool->tool->name(), $tool->scope->modules, $tool->scope->shown],
            $granted,
        );

        $measured = $size->scopes($definition);

        $this->assertCount(4, $measured);
        $this->assertSame($scopes($this->granted->tools), $measured);
        $sdkTools = array_map(static fn (GrantedTool $tool): SdkTool => new SdkTool($tool), $this->granted->tools);
        $this->assertSame(SubstitutePlans::toolChars($sdkTools), $size->chars($definition));
    }

    /**
     * 上限の境目(見積もりが上限と同じなら超えない、1 つ少ない上限なら超える)
     *
     * @return array<string, array{int, bool}>
     */
    public static function limits(): array
    {
        return ['上限と同じ' => [0, false], '上限が 1 少ない' => [-1, true], '上限が 1 多い' => [1, false]];
    }

    #[DataProvider('limits')]
    public function test_the_limit_is_a_tenth_of_the_context_window(int $offset, bool $exceeds): void
    {
        $definition = $this->definition(['ai_read_record', 'ai_search_records'], ['m_tasks']);
        $tokens = app(DefinitionToolSize::class)->tokens($definition);
        config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => ($tokens + $offset) * 10]);

        $size = app(DefinitionToolSize::class);

        $this->assertSame($tokens + $offset, $size->limit());
        $this->assertSame($exceeds, $size->exceeds($definition));
    }

    /** 実行の始めは、外した後の大きさが上限を超えれば失敗(設定)にする(外して直せないため) */
    public function test_a_run_start_over_the_limit_is_refused(): void
    {
        $this->startRecordToolRun(['m_tasks']);
        config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => 10]);

        $definition = $this->run->agentDefinition()->sole();

        $refused = $this->catching(fn () => app(PrepareConversationRun::class)->execute($this->run, $definition));

        $this->assertInstanceOf(DefinitionTooLarge::class, $refused);
        $this->assertSame(AiRunErrorCode::Setting, $refused->errorCode());
    }

    private function catching(Closure $run): ?Throwable
    {
        try {
            $run();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * @param  list<string>  $tools
     * @param  list<string>  $knowledge
     */
    private function definition(array $tools, array $knowledge): AiAgentDefinition
    {
        if (in_array('m_refs_open', $knowledge, true)) {
            $this->recordModule('m_refs_open', []);
        }

        return AiAgentDefinition::factory()->create(['tools' => $tools, 'knowledge' => $knowledge]);
    }
}
