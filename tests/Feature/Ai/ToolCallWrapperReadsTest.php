<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Models\Records\Record;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Records\Api\RecordListQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Tests\Support\TransactionalTestCase;

/**
 * 許すに当たる読むの道具の中の判定(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B3・B4・10.4): 偽の読む道具の中の閲覧の判定を通らない 1 件は
 * 見つからない、一覧は行の判定で絞られる。判定の関数から道具の読み取りまでが 1 つの使い回さない区間にあるので、外のまとまりで判定を求める者の
 * 事実を覚えた後でも、判定の関数の後・道具の読み取りの前に外したロールが道具の中の閲覧の判定に効く(判定の関数だけを囲む作りでは見える)。
 */
final class ToolCallWrapperReadsTest extends TransactionalTestCase
{
    use RunsToolCalls;

    private int $visible;

    private int $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
        $module = $this->modules['m_view']->id;
        $record = fn (bool $restricted): int => Record::factory()
            ->create(['record_module_id' => $module, 'row_access_restricted' => $restricted])->id;
        $this->visible = $record(false);
        $this->hidden = $record(true);
        $this->tool('ai_read')->executor = $this->read(...);
    }

    public function test_a_record_that_does_not_pass_the_view_check_is_not_found(): void
    {
        $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_view', 'id' => $this->hidden]),
            self::toolCall('c2', 'ai_read', ['module' => 'm_view', 'id' => $this->visible]),
        ]);

        $bodies = array_column($this->storedContents(), 'body');
        $this->assertSame(json_encode(['error' => AgentToolResult::NOT_FOUND], JSON_UNESCAPED_UNICODE), $bodies[0]);
        $this->assertSame('{"id":'.$this->visible.'}', $bodies[1]);
    }

    public function test_a_list_is_narrowed_by_the_row_check(): void
    {
        $this->wrap([self::toolCall('c1', 'ai_read', ['module' => 'm_view'])]);

        $this->assertSame('{"ids":['.$this->visible.']}', $this->storedContents()[0]['body']);
    }

    public function test_a_role_revoked_after_the_judgement_takes_effect_in_the_read_of_the_tool(): void
    {
        $this->tool('ai_read')->executor = function (array $arguments, AgentToolSubject $subject): AgentToolResult {
            $this->revokeRole();

            return $this->read($arguments, $subject);
        };

        app(AuthorizationReuse::class)->within(function (): void {
            $this->judgeOutside('m_view');
            $this->wrap([self::toolCall('c1', 'ai_read', ['module' => 'm_view', 'id' => $this->visible])]);
        });

        $notFound = json_encode(['error' => AgentToolResult::NOT_FOUND], JSON_UNESCAPED_UNICODE);
        $this->assertSame($notFound, $this->storedContents()[0]['body']);
    }

    /**
     * 偽の読む道具の中身(id があれば閲覧の判定の後の 1 件、無ければ一覧)。
     *
     * @param  array<string, mixed>  $arguments
     */
    private function read(array $arguments, AgentToolSubject $subject): AgentToolResult
    {
        return isset($arguments['id']) ? $this->readOne((int) $arguments['id'], $subject) : $this->readList($subject);
    }

    private function readOne(int $id, AgentToolSubject $subject): AgentToolResult
    {
        $visible = app(RecordViewAccess::class)->canView($subject->requester(), 'm_view', $id, $subject->date());

        return $visible ? new AgentToolResult(['id' => $id]) : AgentToolResult::notFound();
    }

    private function readList(AgentToolSubject $subject): AgentToolResult
    {
        $listed = app(RecordListQuery::class)->list(
            $subject->requester(),
            'm_view',
            [],
            null,
            1,
            50,
            null,
            $subject->date(),
            $subject->localeCode(),
        );

        return new AgentToolResult(['ids' => array_column($listed['records']['data'], 'id')]);
    }
}
