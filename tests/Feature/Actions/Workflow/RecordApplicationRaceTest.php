<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Records\DeleteRecord;
use App\Actions\Workflow\RecordApplicationSteps;
use App\Models\Master\Department;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Workflow\RecordApplyTargets;
use App\Queries\Workflow\WorkflowRecordHolds;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Tests\Support\AppliesFromRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の、0 の確かめの後の競合(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 4 節の 1・7 節): 確かめの読み取りを
 * 競合の後の形に差し替え(別の申請が入った・レコードが消された)、申請の行の挿入の部分一意索引の違反は 422 record、record_id の外部キーの違反は 404 になり、
 * ほかの制約の違反は捕まえずに上がることを確かめる。どれも何も書かない。
 */
final class RecordApplicationRaceTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use CatchesValidationErrors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_an_application_racing_with_another_on_the_same_record_gets_422_record(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $record->id);
        $this->checkAs(held: false, moduleCode: null);
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $record->id));

        $this->assertSame(['record' => RecordApplicationSteps::HELD], $errors);
        $this->assertSame($counts, $this->workflowCounts());
    }

    public function test_a_record_deleted_after_the_check_gets_404(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        app(DeleteRecord::class)->execute($this->contextOf($u0001), self::PROJECT, $record->id);
        $this->checkAs(held: false, moduleCode: self::PROJECT);
        $counts = $this->workflowCounts();

        $outcome = $this->outcome(fn () => $this->applyFromRecord($u0001, $record->id, version: ''));

        $this->assertSame('404', $outcome);
        $this->assertSame($counts, $this->workflowCounts());
    }

    public function test_other_constraint_violations_of_the_case_row_are_not_caught(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $missingDepartmentId = (int) Department::query()->max('id') + 1000;
        $this->app->instance(CurrentDepartment::class, new class($missingDepartmentId) extends CurrentDepartment
        {
            public function __construct(private int $departmentId) {}

            public function departmentIdOn(int $userId, ?int $selectedDepartmentId, DateTimeInterface $date): int
            {
                return $this->departmentId;
            }
        });

        try {
            $this->applyFromRecord($u0001, $record->id);
            $this->fail('ほかの制約の違反が上がらなかった');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('"workflow_cases_department_id_foreign"', $exception->getMessage());
        }
    }

    /**
     * 0 の確かめが読む持ち主と業務モジュールを、競合の後の形に差し替える(業務モジュールの null = 本物のまま読む)。
     */
    private function checkAs(bool $held, ?string $moduleCode): void
    {
        [$display, $holds] = [app(RecordDisplayQuery::class), app(WorkflowRecordHolds::class)];
        $targets = new class($display, $holds, $held, $moduleCode) extends RecordApplyTargets
        {
            public function __construct(
                RecordDisplayQuery $display,
                WorkflowRecordHolds $holds,
                private bool $held,
                private ?string $moduleCode,
            ) {
                parent::__construct($display, $holds);
            }

            public function moduleCodeOf(int $recordId): ?string
            {
                return $this->moduleCode ?? parent::moduleCodeOf($recordId);
            }

            public function isHeld(int $recordId): bool
            {
                return $this->held;
            }
        };
        $this->app->instance(RecordApplyTargets::class, $targets);
    }
}
