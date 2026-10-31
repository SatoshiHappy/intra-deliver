<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;

/**
 * ノードの処理対象者の指定を、文を作るための名前つきの値にしたもの({@see StepDescriptions} が読む。{@see StepTargets} が作る)。
 *
 * @phpstan-type SubjectRow array{type: string, id: int, name: string}
 */
final readonly class StepTarget
{
    /**
     * @param  list<SubjectRow>  $rows  対象者の行(対象者で指定だけ)
     */
    private function __construct(
        public WorkflowTargetKind $kind,
        public array $rows = [],
        public ?WorkflowRelativeBase $base = null,
        public ?WorkflowRelativeScope $scope = null,
        public ?int $companyPostId = null,
        public ?string $companyPostName = null,
        public ?string $designatorName = null,
        public ?int $min = null,
        public ?int $max = null,
    ) {}

    /**
     * @param  list<SubjectRow>  $rows
     */
    public static function fixed(array $rows): self
    {
        return new self(WorkflowTargetKind::Fixed, rows: $rows);
    }

    public static function relative(
        WorkflowRelativeBase $base,
        WorkflowRelativeScope $scope,
        ?int $companyPostId,
        ?string $companyPostName,
    ): self {
        return new self(
            WorkflowTargetKind::Relative,
            base: $base,
            scope: $scope,
            companyPostId: $companyPostId,
            companyPostName: $companyPostName,
        );
    }

    public static function designated(string $designatorName, int $min, int $max): self
    {
        return new self(WorkflowTargetKind::Designated, designatorName: $designatorName, min: $min, max: $max);
    }
}
