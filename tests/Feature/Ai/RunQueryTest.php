<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Ai\Api\RunQuery;
use App\Queries\Ai\Api\RunView;
use App\Queries\Authz\Api\Requester;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * AI の実行の閲覧(docs の 設計/AI/公開の口.md 3 節): 依頼者は見られる、実行の業務モジュールの read を許される者は見られる、
 * 明細の行が対象の実行は親の業務モジュールで判定する、許されなければ null、業務モジュールが無ければ(消えていても)null で例外にしない、実行が無ければ null。原文・費用は出さない。
 * 実行の状態の一覧は主体の判定をせず、実行ごとの状態の文字列を返す(無い id は入らない・空の一覧は問い合わせない)。
 */
final class RunQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestPromptKinds;

    public function test_the_statuses_of_runs_map_each_run_to_its_state_and_leave_out_missing_ids(): void
    {
        $queued = $this->aiRun(null);
        $running = AiRun::factory()->running()->create();
        $succeeded = AiRun::factory()->succeeded()->create();
        $failed = AiRun::factory()->failed()->create();

        $ids = [$queued->id, $running->id, $succeeded->id, $failed->id, 999_999_999];

        $expected = [
            $queued->id => 'queued',
            $running->id => 'running',
            $succeeded->id => 'succeeded',
            $failed->id => 'failed',
        ];

        $this->assertSame($expected, app(RunQuery::class)->statusesOf($ids));
    }

    public function test_the_statuses_of_an_empty_list_do_not_query(): void
    {
        DB::enableQueryLog();

        $this->assertSame([], app(RunQuery::class)->statusesOf([]));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_requester_can_view_the_run_without_a_record(): void
    {
        $run = $this->aiRun(null);

        $view = $this->show($run->requester_user_id, $run->id);

        $this->assertInstanceOf(RunView::class, $view);
        $this->assertSame([$run->id, 'meeting.minutes', AiRunStatus::Queued, null, null], [$view->id, $view->kind, $view->status, $view->errorCode, $view->failureMessage]);
        $this->assertSame(User::query()->findOrFail($run->requester_user_id)->code, $view->requester['code'] ?? null);
        $this->assertNull($this->show($this->otherUserId(), $run->id));
    }

    public function test_others_can_view_only_with_read_on_the_module_of_the_record(): void
    {
        $readable = $this->aiRun($this->recordModule('meetings', [RecordModuleAction::Read])->code);
        $unreadable = $this->aiRun($this->recordModule('secrets', [RecordModuleAction::Create])->code);

        $this->assertSame($readable->id, $this->show($this->otherUserId(), $readable->id)?->id);
        $this->assertNull($this->show($this->otherUserId(), $unreadable->id));
        $this->assertNull($this->show(null, $unreadable->id));
    }

    public function test_a_run_on_a_details_row_is_visible_with_read_on_the_parent_module(): void
    {
        $this->registerTestPromptKinds();
        Queue::fake();
        $module = $this->recordModule('meetings', [RecordModuleAction::Read]);
        $tasks = $this->detailsField($module, 'agenda');
        $parent = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $parent->id]);

        $runId = app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(recordId: $row->id));

        $this->assertSame('meetings', AiRun::query()->findOrFail($runId)->record_module_code);
        $this->assertSame($runId, $this->show($this->otherUserId(), $runId)?->id);
    }

    public function test_a_vanished_module_and_a_missing_run_are_not_visible(): void
    {
        $vanished = $this->aiRun('vanished');

        $this->assertNull($this->show($this->otherUserId(), $vanished->id));
        $this->assertNull($this->show($this->otherUserId(), 999_999));
    }

    public function test_a_failed_run_shows_the_screen_message_not_the_exception(): void
    {
        $run = $this->aiRun(null, ['status' => AiRunStatus::Failed, 'error_code' => AiRunErrorCode::Provider, 'error_message' => 'SQLSTATE や鍵を含みうる例外の文', 'finished_at' => now()]);

        $view = $this->show($run->requester_user_id, $run->id);

        $this->assertSame([AiRunErrorCode::Provider, RunView::FAILURE_MESSAGE], [$view?->errorCode, $view?->failureMessage]);
        $this->assertNotNull($view?->finishedAt);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function aiRun(?string $moduleCode, array $attributes = []): AiRun
    {
        $recordId = $moduleCode === null ? null : $this->recordIdIn($moduleCode);

        return AiRun::factory()->create([
            'record_id' => $recordId,
            'original_record_id' => $recordId ?? ($moduleCode === null ? null : 999_999),
            'record_module_code' => $moduleCode,
            ...$attributes,
        ]);
    }

    /** 業務モジュールのレコードの id(業務モジュールが無ければ null = レコードを消した後の実行) */
    private function recordIdIn(string $moduleCode): ?int
    {
        $moduleId = RecordModule::query()->where('code', $moduleCode)->value('id');

        return $moduleId === null ? null : Record::factory()->create(['record_module_id' => $moduleId])->id;
    }

    private function otherUserId(): int
    {
        return (int) User::query()->orderBy('id')->skip(1)->value('id');
    }

    private function show(?int $userId, int $runId): ?RunView
    {
        return app(RunQuery::class)->show(new Requester($userId, null), $runId, CarbonImmutable::parse(self::TODAY), 'ja');
    }
}
