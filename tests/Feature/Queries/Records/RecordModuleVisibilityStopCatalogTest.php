<?php

namespace Tests\Feature\Queries\Records;

use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordModuleVisibilityStops;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use App\Queries\Workflow\WorkflowRecordModuleVisibilityStops;
use Database\Seeders\WorkflowApplicationModules;
use Tests\Support\TransactionalTestCase;

/**
 * 表示の止めの実装を束ねる 1 か所とワークフローの実装(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の
 * RecordModuleVisibilityStops の行。ADR 0031 の決定 2・4): 束ねる口は実装を登録の順に聞き、最初の非 null を返す(どれも null なら null)。
 * ワークフローの実装は、コンテンツが指す業務モジュールで理由の文言、指さない業務モジュールで null。初期データのコンテンツ 2 つを使う。
 */
final class RecordModuleVisibilityStopCatalogTest extends TransactionalTestCase
{
    public function test_the_workflow_stops_the_modules_that_a_content_points_to(): void
    {
        $stops = app(WorkflowRecordModuleVisibilityStops::class);

        foreach ([WorkflowApplicationModules::PLAN, WorkflowApplicationModules::ISSUE] as $code) {
            $reason = $stops->stopReason($this->moduleId($code));
            $this->assertSame(WorkflowRecordModuleVisibilityStops::REASON, $reason, $code);
        }

        $this->assertNull($stops->stopReason($this->moduleId('project')));
        $this->assertSame('申請書の業務モジュールは、ワークフローのアプリに表示します。', WorkflowRecordModuleVisibilityStops::REASON);
    }

    public function test_the_catalog_returns_the_first_reason_in_the_registration_order(): void
    {
        $project = $this->moduleId('project');
        $issue = $this->moduleId('project_issue');
        $this->register('test.stops.0', [$issue => '1 番目']);
        $this->register('test.stops.1', [$project => '2 番目', $issue => '2 番目']);
        $this->register('test.stops.2', [$project => '3 番目']);

        $this->assertSame('2 番目', $this->catalog()->stopReason($project));
        $this->assertSame('1 番目', $this->catalog()->stopReason($issue));
        $this->assertSame(WorkflowRecordModuleVisibilityStops::REASON, $this->catalog()->stopReason(
            $this->moduleId(WorkflowApplicationModules::PLAN),
        ));
        $this->assertNull($this->catalog()->stopReason($this->moduleId('meeting')));
    }

    public function test_without_any_implementation_there_is_no_stop(): void
    {
        $catalog = new RecordModuleVisibilityStopCatalog([]);

        $this->assertNull($catalog->stopReason($this->moduleId(WorkflowApplicationModules::PLAN)));
    }

    /**
     * 止めの実装を 1 つ、起動と同じタグで登録の順の後ろに足す(起動が登録したワークフローの実装が先頭)。
     *
     * @param  array<int, string>  $reasons  業務モジュールの id → 理由
     */
    private function register(string $name, array $reasons): void
    {
        $this->app->instance($name, new class($reasons) implements RecordModuleVisibilityStops
        {
            /**
             * @param  array<int, string>  $reasons
             */
            public function __construct(private array $reasons) {}

            public function stopReason(int $recordModuleId): ?string
            {
                return $this->reasons[$recordModuleId] ?? null;
            }
        });
        $this->app->tag([$name], RecordModuleVisibilityStops::TAG);
    }

    private function catalog(): RecordModuleVisibilityStopCatalog
    {
        return app(RecordModuleVisibilityStopCatalog::class);
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }
}
