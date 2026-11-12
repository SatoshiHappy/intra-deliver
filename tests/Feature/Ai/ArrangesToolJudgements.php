<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolJudge;
use App\Actions\Ai\ToolCalls\ToolJudgement;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Records\Api\RecordFormQuery;
use LogicException;
use Tests\Feature\Ai\Tools\FakeTools;

/**
 * 2 段目の判定のテストの準備: 読む・作る・直すの偽の道具と、性質ごとに要る操作を依頼者のロールに許した業務モジュール(読む = m_view、作る = m_create、
 * 直す = m_update)・ナレッジの外の業務モジュール(m_out)・始めから止めのある業務モジュール(m_stopped)。実行の始めの 1 段目で渡す道具を決めておく
 * (登録にあって定義に無い道具 ai_extra は渡さない)。
 */
trait ArrangesToolJudgements
{
    use ArrangesToolPermissions;

    private const ALL_ACTIONS = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];

    private AiRun $run;

    private GrantedTools $granted;

    /** @var array<string, RecordModule> */
    private array $modules = [];

    /**
     * @param  list<string>  $extraKnowledge  ナレッジに足す業務モジュール(作っておく)
     */
    private function arrangeJudgements(array $extraKnowledge = []): void
    {
        $this->arrangeRequester();
        $this->modules['m_view'] = $this->moduleFor('m_view', [RecordModuleAction::Read]);
        $this->modules['m_create'] = $this->moduleFor('m_create', [RecordModuleAction::Create]);
        $readUpdate = [RecordModuleAction::Read, RecordModuleAction::Update];
        $this->modules['m_update'] = $this->moduleFor('m_update', $readUpdate);
        $this->modules['m_out'] = $this->moduleFor('m_out', self::ALL_ACTIONS);
        $this->modules['m_stopped'] = $this->moduleFor('m_stopped', self::ALL_ACTIONS);
        $this->stop($this->modules['m_stopped']);
        $this->arrangeTools(['m_view', 'm_create', 'm_update', 'm_stopped', ...$extraKnowledge]);
    }

    /**
     * 読む・作る・直すの偽の道具を登録し、ナレッジの実行を作って 1 段目を済ませる。
     *
     * @param  list<string>  $knowledge
     */
    private function arrangeTools(array $knowledge): void
    {
        $this->app->instance(AgentTools::class, FakeTools::registry([
            FakeTools::read(),
            FakeTools::create(),
            FakeTools::update(),
            FakeTools::read('ai_extra'),
        ]));
        $this->run = $this->runFor(['ai_read', 'ai_create', 'ai_update'], $knowledge);
        $this->granted = app(ToolGrants::class)->grant(
            $this->run->agentDefinition()->sole(),
            app(ToolSubjects::class)->for($this->run),
        );
    }

    /** 区間の外で判定して、判定を求める者の事実を覚えさせる */
    private function judgeOutside(string $module): void
    {
        $subject = app(ToolSubjects::class)->for($this->run);
        app(RecordFormQuery::class)->allows($subject->requester(), $module, RecordModuleAction::Read, $subject->date());
    }

    /** 道具の呼び出しを、包みと同じく使い回さない区間の中で判定する */
    private function judgeCall(string $toolName, string $module): ToolJudgement
    {
        $granted = $this->granted->find($toolName) ?? throw new LogicException("{$toolName} を渡していない");
        $tool = $granted->tool;

        if (! $tool instanceof AgentRecordTool) {
            throw new LogicException("{$toolName} は業務モジュールの道具でない");
        }

        return app(AuthorizationReuse::class)->without(
            fn (): ToolJudgement => app(ToolJudge::class)->judge($tool, ['module' => $module], $granted, $this->run),
        );
    }
}
