<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\ToolArgumentProblems;
use App\Actions\Ai\Tools\ReadRecord;
use App\Actions\Ai\Tools\SearchRecords;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolAction;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\TestCase;

/**
 * 本物の探す・読むの約束の答えと登録(docs の 設計/AI/道具と権限/README.md 10.1 の約束の表・名前・渡す道具の並び、上限 = 設計/AI/道具と権限/
 * まとめた実行と送るデータ.md 10.7): 起動がタグで登録し、起動の時の確かめが通る。汎用の道具なので名前の順で製品の道具の前に並ぶ。
 */
final class RecordToolsContractTest extends TestCase
{
    public function test_the_two_tools_are_registered_and_pass_the_startup_check(): void
    {
        $tools = app(AgentTools::class);

        $this->assertSame([], $tools->problems());
        $this->assertSame(['ai_read_record', 'ai_search_records'], array_map(
            fn (AgentTool $tool): string => $tool->name(),
            $tools->all(),
        ));
        $this->assertSame([true, true], array_map($tools->isGeneric(...), $tools->all()));
    }

    public function test_the_answers_of_the_contract(): void
    {
        $answers = array_map(fn (AgentRecordTool $tool): array => [
            $tool->name(),
            $tool->aliases(),
            $tool->displayName(),
            $tool->isReadOnly(),
            $tool->requiredAction(),
            $tool->targetModules(),
            $tool->maxResultLength(),
            $tool->largeResultTemplate(),
            $tool->validate(['module' => 'm', 'id' => 1], new AgentToolScope, []),
        ], [app(SearchRecords::class), app(ReadRecord::class)]);

        $view = AiToolAction::View;
        $this->assertSame([
            ['ai_search_records', [], '検索', true, $view, null, 20_000, AiInstructionTemplate::LargeResultSearch, null],
            ['ai_read_record', [], '読み取り', true, $view, null, null, AiInstructionTemplate::LargeResultRead, null],
        ], $answers);
    }

    public function test_generic_tools_come_by_name_before_a_product_tool(): void
    {
        $tools = new AgentTools(
            [FakeTools::productRead(['m']), app(SearchRecords::class), app(ReadRecord::class)],
            [...AgentTools::DEFAULT_NAMESPACES, FakeTools::NAMESPACES[1]],
        );

        $this->assertSame(['ai_read_record', 'ai_search_records', 'widget_read'], array_map(
            fn (AgentTool $tool): string => $tool->name(),
            $tools->all(),
        ));
    }

    /** 呼び出しが指す業務モジュールは引数のまま(無いコードでも返す = 10.3 の B10) */
    public function test_the_module_of_a_call_is_the_argument_even_for_a_missing_code(): void
    {
        foreach ([app(SearchRecords::class), app(ReadRecord::class)] as $tool) {
            $this->assertSame(['no_such_module', null], [
                $tool->moduleOf(['module' => 'no_such_module', 'id' => 1]),
                $tool->moduleOf([]),
            ]);
        }
    }

    /** 引数の形: 業務モジュールは両方で必須、読むは id も必須。ほかは省ける(10.3 の B11) */
    public function test_the_argument_shapes(): void
    {
        $problems = app(ToolArgumentProblems::class);

        $this->assertSame([], $problems->of(app(SearchRecords::class), ['module' => 'm']));
        $this->assertSame([], $problems->of(app(SearchRecords::class), [
            'module' => 'm',
            'conditions' => ['f' => ['from' => '1']],
            'sort' => '-f',
            'page' => 2,
            'fields' => ['f'],
        ]));
        $this->assertSame(['module: 欠けている'], $problems->of(app(SearchRecords::class), []));
        $this->assertSame([], $problems->of(app(ReadRecord::class), [
            'module' => 'm',
            'id' => 1,
            'comments_page' => 2,
            'revisions_page' => 3,
        ]));
        $this->assertSame(['module: 欠けている', 'id: 欠けている'], $problems->of(app(ReadRecord::class), []));
    }
}
