<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Records\Api\RecordFormQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 本物の読むの閲覧の判定と見つからない(docs の 設計/AI/道具と権限/README.md 10.2 の読むの判定、表 1 の B3・B10 と競合の項 =
 * 設計/AI/道具と権限/道具の判定と今の権限.md 10.3、閲覧の判定の前に値を読まない = 設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4):
 * 閲覧の判定を通らない・無い・ほかの業務モジュールの・明細の行の id は、閲覧の判定のほかの口を呼ばずに見つからない。判定の後に消えた競合は、
 * 消えたのがレコードか業務モジュールかを問わず見つからないで、ほかの例外は包みの失敗の結果。
 */
final class ReadRecordTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;

    private const NOT_FOUND = ['error' => AgentToolResult::NOT_FOUND];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->moduleFor('m_other', [RecordModuleAction::Read]);
        $this->logRecordReads();
        $this->startRecordToolRun(['m_tasks', 'm_other']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unseenRecords(): array
    {
        return [
            '行の判定を通らない' => ['restricted'],
            '無い id' => ['missing'],
            'ほかの業務モジュールのレコード(そちらでは読める)' => ['other module'],
            '明細の行' => ['detail row'],
            '判定の関数の後に閲覧のロールを外した' => ['revoked'],
        ];
    }

    #[DataProvider('unseenRecords')]
    public function test_a_record_the_view_check_does_not_pass_is_not_found_without_reading_it(string $case): void
    {
        $id = match ($case) {
            'restricted' => $this->storedTask(['title' => '見えない'], restricted: true),
            'missing' => (int) Record::query()->max('id') + 1,
            'other module' => Record::factory()->create(['record_module_id' => $this->moduleId('m_other')])->id,
            'detail row' => (int) Record::query()->where('parent_record_id', $this->task([], ['品目']))->value('id'),
            default => $this->storedTask(['title' => '見える']),
        };
        $this->reads->before['RecordViewAccess::canView'] = $case === 'revoked'
            ? $this->revokeRole(...)
            : fn () => null;

        $content = $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);

        $this->assertSame(self::NOT_FOUND, self::body($content));
        $this->assertSame(['RecordViewAccess::canView'], $this->reads->calls);
        $this->assertSame([], $this->toolRow()->records);
    }

    /** 区間: 外のまとまりで判定を求める者の事実を覚えた後でも、判定の関数の後・閲覧の判定の前に外したロールが効く */
    public function test_a_role_revoked_between_the_judgement_and_the_view_check_takes_effect(): void
    {
        $id = $this->storedTask(['title' => '見える']);
        $this->reads->before['RecordViewAccess::canView'] = $this->revokeRole(...);

        app(AuthorizationReuse::class)->within(function () use ($id): void {
            $subject = app(ToolSubjects::class)->for($this->run);
            $forms = app(RecordFormQuery::class);
            $forms->allows($subject->requester(), 'm_tasks', RecordModuleAction::Read, $subject->date());
            $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);
        });

        $this->assertSame(self::NOT_FOUND, self::body($this->lastContent()));
    }

    /** 申請書の業務モジュールは表示の止めで範囲の外なので、閲覧を許しナレッジに入れても B10(道具を呼ばない) */
    public function test_a_record_of_an_application_form_is_not_found_by_the_stop(): void
    {
        $form = RecordModule::query()->whereKey(WorkflowContent::query()->value('record_module_id'))->firstOrFail();
        $this->permit($form->code, [RecordModuleAction::Read]);
        $id = Record::factory()->create(['record_module_id' => $form->id])->id;
        $this->startRecordToolRun(['m_tasks', $form->code]);

        $this->callTool('ai_read_record', ['module' => $form->code, 'id' => $id]);

        $this->assertSame(self::NOT_FOUND, self::body($this->lastContent()));
        $this->assertSame(AiToolDenialReason::NotFound->value, $this->toolRow()->decision_reason);
        $this->assertSame([], $this->reads->calls);
    }

    /**
     * 判定の後に消えた競合の場所(detail の前と、detail の後のほかの口の前)と、口が投げる例外。
     *
     * @return array<string, array{string, string}>
     */
    public static function races(): array
    {
        $cases = [];

        $points = ['RecordDisplayQuery::detail', 'RecordActivityQuery::comments', 'RecordAttachmentQuery::forRecord'];

        foreach ($points as $point) {
            foreach (['レコード', '業務モジュール'] as $vanished) {
                $cases["{$point} の前に{$vanished}が消えた"] = [$point, $vanished];
            }
        }

        return $cases;
    }

    #[DataProvider('races')]
    public function test_a_record_or_a_module_vanishing_after_the_view_check_is_not_found(
        string $point,
        string $vanished,
    ): void {
        $id = $this->task(['title' => '消える']);
        $model = $vanished === 'レコード' ? Record::class : RecordModule::class;
        $missing = (new ModelNotFoundException)->setModel($model, [$id]);
        $this->reads->before[$point] = fn () => throw $missing;

        $content = $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);

        $this->assertSame(self::NOT_FOUND, self::body($content));
        $this->assertSame(AiToolCallStatus::Done, $this->toolRow()->status);
        $this->assertSame($point, $this->reads->calls[count($this->reads->calls) - 1]);
    }

    /** 本物の競合: 閲覧の判定の後・詳細の値の前にレコードを消す */
    public function test_a_record_deleted_after_the_view_check_is_not_found(): void
    {
        $id = $this->storedTask(['title' => '消える']);
        $this->reads->before['RecordDisplayQuery::detail'] = fn () => Record::query()->whereKey($id)->delete();

        $content = $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);

        $this->assertSame(self::NOT_FOUND, self::body($content));
    }

    /** 同じ口が見つからないのほかの例外を投げると、包みの失敗の結果(B3 にならない) */
    public function test_another_exception_of_the_same_api_is_a_tool_failure(): void
    {
        $id = $this->task(['title' => '壊れる']);
        $this->reads->before['RecordActivityQuery::comments'] = fn () => throw new RuntimeException('壊れた');

        $content = $this->callTool('ai_read_record', ['module' => 'm_tasks', 'id' => $id]);

        $failure = [$content['template'], $content['is_error']];
        $this->assertSame([AiInstructionTemplate::ToolError->value, true], $failure);
        $this->assertSame(AiToolCallStatus::ToolError, $this->toolRow()->status);
    }

    /**
     * 会話の保存に最後に足した道具の結果の中身。
     *
     * @return array<string, mixed>
     */
    private function lastContent(): array
    {
        $items = $this->store->read($this->run->id) ?? [];

        return $items === [] ? [] : $items[array_key_last($items)]->content;
    }

    private function moduleId(string $code): int
    {
        return (int) RecordModule::query()->where('code', $code)->value('id');
    }
}
