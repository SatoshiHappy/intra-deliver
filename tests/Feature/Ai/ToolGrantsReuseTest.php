<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\Support\TransactionalTestCase;

/**
 * 1 段目の口の呼び方(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.4): 定義は業務モジュールごとに 1 回、許された操作の 1 つ(閲覧が要る道具が
 * あれば閲覧)で読む。無い・明細の業務モジュールには止めと判定を、無効な依頼者には判定を呼ばない。全体を使い回さない区間で囲む(同じジョブの
 * 前の判定の後に外したロールが次の 1 段目に効き、1 つ目の業務モジュールの判定の後に外したロールが 2 つ目に効く)。
 */
final class ToolGrantsReuseTest extends TransactionalTestCase
{
    use ArrangesToolPermissions;

    private const ALL = [RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Update];

    private CountingRecordFormQuery $forms;

    private CountingModuleDirectory $directory;

    private CountingEffectiveUsers $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeRequester();
        $this->detailsField($this->moduleFor('m_all', self::ALL), 'm_all_tasks');
        $this->moduleFor('m_create', [RecordModuleAction::Create]);
        $this->moduleFor('m_second', self::ALL);
        $this->app->instance(AgentTools::class, FakeTools::registry([
            FakeTools::read(),
            FakeTools::create(),
            FakeTools::update(),
        ]));
        $this->forms = app(CountingRecordFormQuery::class);
        $this->directory = app(CountingModuleDirectory::class);
        $this->users = app(CountingEffectiveUsers::class);
        $this->app->instance(RecordFormQuery::class, $this->forms);
        $this->app->instance(RecordModuleDirectory::class, $this->directory);
        $this->app->instance(EffectiveUsers::class, $this->users);
    }

    public function test_definitions_are_read_once_per_module_with_view_if_any_tool_needs_it(): void
    {
        $this->granted($this->runFor(['ai_read', 'ai_create', 'ai_update'], ['m_all', 'm_create']));

        $this->assertSame(['m_all read', 'm_create create'], $this->forms->definitionCalls);
        $this->assertSame(1, $this->directory->listCalls);
        $this->assertSame(1, $this->users->calls);
    }

    /** 無い・明細の業務モジュールには止めと判定を呼ばず、無効な依頼者には判定を呼ばない */
    public function test_missing_modules_and_an_inactive_requester_skip_the_later_calls(): void
    {
        $this->granted($this->runFor(['ai_read'], ['m_gone', 'm_all_tasks_rows']));

        $this->assertSame([[], []], [$this->directory->stopCalls, $this->forms->allowsCalls]);

        $this->deactivateRequester();
        $granted = $this->granted($this->runFor(['ai_read'], ['m_all']));

        $this->assertSame([[], ['m_all']], [$granted->tools, $this->directory->stopCalls]);
        $this->assertSame([], $this->forms->allowsCalls);
    }

    /** 外の使い回すまとまりで事実を覚えた後でも、前の 1 段目の後に外したロールが次の 1 段目に効く */
    public function test_a_role_revoked_after_an_earlier_grant_in_the_same_job_takes_effect(): void
    {
        $run = $this->runFor(['ai_read'], ['m_all']);

        $after = app(AuthorizationReuse::class)->within(function () use ($run): GrantedTools {
            $this->assertSame(['ai_read'], $this->granted($run)->names());
            $this->assertTrue($this->allowsOutside());
            $this->revokeRole();

            return $this->granted($run);
        });

        $this->assertSame([], $after->tools);
    }

    /**
     * 1 つ目の業務モジュールの判定の後・2 つ目の判定の前に外したロールが、2 つ目に効く(1 つ目だけを囲む作りでは入る)。1 つ目も、判定の後に
     * 権限が無くなって定義を読めないので入らない
     */
    public function test_a_role_revoked_between_two_modules_of_one_grant_keeps_the_second_out(): void
    {
        $run = $this->runFor(['ai_read'], ['m_all', 'm_second']);
        $decided = [];
        $revoking = function (string $code, RecordModuleAction $_, bool $ok) use (&$decided): void {
            $decided[] = [$code, $ok];
            $code === 'm_all' ? $this->revokeRole() : null;
        };

        $granted = app(AuthorizationReuse::class)->within(function () use ($run, $revoking): GrantedTools {
            $this->assertTrue($this->allowsOutside());
            $this->forms->afterAllows = $revoking;

            return $this->granted($run);
        });

        $this->assertSame([['m_all', true], ['m_second', false]], $decided);
        $this->assertSame([], $granted->tools);
    }

    /** 区間の外で判定して、判定を求める者の事実を覚えさせる */
    private function allowsOutside(): bool
    {
        $subject = app(ToolSubjects::class)->for($this->runFor([], []));

        $forms = app(RecordFormQuery::class);

        return $forms->allows($subject->requester(), 'm_second', RecordModuleAction::Read, $subject->date());
    }

    private function granted(AiRun $run): GrantedTools
    {
        return app(ToolGrants::class)->grant($run->agentDefinition()->sole(), app(ToolSubjects::class)->for($run));
    }
}
