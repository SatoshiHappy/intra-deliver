<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\Support\TransactionalTestCase;

/**
 * 1 段目の渡す道具と渡せる範囲(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目の表)。表の各行を通すため、業務モジュールごとに
 * 依頼者の操作の権限を分けて展開する。
 */
final class ToolGrantsTest extends TransactionalTestCase
{
    use ArrangesToolPermissions;

    /** 業務モジュール → 依頼者のロールに許す操作 */
    private const MODULES = [
        'm_none' => [],
        'm_view' => [RecordModuleAction::Read],
        'm_create' => [RecordModuleAction::Create],
        'm_update' => [RecordModuleAction::Update],
        'm_view_update' => [RecordModuleAction::Read, RecordModuleAction::Update],
        'm_all' => [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update],
        'm_stopped' => [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update],
    ];

    /** ナレッジ(無くなった業務モジュールと明細のモジュールを含む) */
    private const KNOWLEDGE = [...self::CODES, 'm_gone', 'm_all_tasks_rows'];

    private const CODES = ['m_none', 'm_view', 'm_create', 'm_update', 'm_view_update', 'm_all', 'm_stopped'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeRequester();

        foreach (self::MODULES as $code => $actions) {
            $module = $this->moduleFor($code, $actions);
            $code === 'm_all' ? $this->detailsField($module, 'm_all_tasks') : null;
            $code === 'm_stopped' ? $this->stop($module) : null;
        }

        $this->app->instance(AgentTools::class, FakeTools::registry([
            FakeTools::update(),
            FakeTools::read(),
            FakeTools::create(),
            FakeTools::productRead(['p_own']),
        ]));
        $this->moduleFor('p_own', [RecordModuleAction::Read]);
    }

    public function test_each_nature_gets_the_modules_whose_required_operations_the_requester_has(): void
    {
        $granted = $this->granted($this->runFor(['ai_read', 'ai_create', 'ai_update', 'widget_read'], self::KNOWLEDGE));

        $this->assertSame(['ai_create', 'ai_read', 'ai_update', 'widget_read'], $granted->names());
        $this->assertSame([
            'ai_create' => ['m_create', 'm_all'],
            'ai_read' => ['m_view', 'm_view_update', 'm_all'],
            'ai_update' => ['m_view_update', 'm_all'],
            'widget_read' => ['p_own'],
        ], self::scopes($granted));
        $this->assertSame([], $granted->removedNames);
        $read = $granted->find('ai_read');
        $this->assertNotNull($read);
        $this->assertSame('業務モジュール m_view・m_view_update・m_all を扱う', $read->tool->description($read->scope));
    }

    /** A2: 有効でない依頼者は渡せる範囲が空で、読む・書く道具は渡さない */
    public function test_an_inactive_requester_gets_no_tools(): void
    {
        $this->deactivateRequester();

        $granted = $this->granted($this->runFor(['ai_read', 'ai_create', 'ai_update', 'widget_read'], self::KNOWLEDGE));

        $this->assertSame([], $granted->tools);
    }

    /** A2: 範囲が空の道具(ナレッジが権限の無い業務モジュールと止めのある業務モジュールだけ)は渡さない */
    public function test_a_tool_with_an_empty_range_is_not_given(): void
    {
        $granted = $this->granted($this->runFor(['ai_read', 'ai_create'], ['m_none', 'm_stopped', 'm_update']));

        $this->assertSame([], $granted->tools);
    }

    /** A1: 定義に無い道具は渡さない。A5: 登録に無い名前は外して知らせ、別名では当てない */
    public function test_tools_not_in_the_definition_are_not_given_and_unregistered_names_are_removed(): void
    {
        $this->app->instance(AgentTools::class, FakeTools::registry([
            new Tools\Generic\GenericTool('ai_read_v2', aliases: ['ai_read_v1']),
            FakeTools::create(),
        ]));

        $granted = $this->granted($this->runFor(['ai_read_v1', 'ai_removed', 'ai_create'], self::KNOWLEDGE));

        $this->assertSame(['ai_create'], $granted->names());
        $this->assertSame(['ai_read_v1', 'ai_removed'], $granted->removedNames);
    }

    /** 製品の道具は自分の対象で、ナレッジに無くても渡る。対象の業務モジュールにも同じ規則を当てる */
    public function test_a_product_tool_uses_its_own_targets(): void
    {
        $this->app->instance(AgentTools::class, FakeTools::registry([
            FakeTools::productRead(['p_own', 'm_none', 'm_stopped', 'm_gone']),
        ]));

        $granted = $this->granted($this->runFor(['widget_read'], []));

        $this->assertSame(['widget_read' => ['p_own']], self::scopes($granted));
    }

    private function granted(AiRun $run): GrantedTools
    {
        $definition = $run->agentDefinition()->sole();

        return app(ToolGrants::class)->grant($definition, app(ToolSubjects::class)->for($run));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function scopes(GrantedTools $granted): array
    {
        $scopes = [];

        foreach ($granted->tools as $tool) {
            $scopes[$tool->tool->name()] = $tool->scope->codes();
        }

        return $scopes;
    }
}
