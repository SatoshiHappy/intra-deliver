<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use DateTimeInterface;
use LogicException;

/**
 * フロー定義の一覧(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 2 節)。業務モジュールの read によらずすべてのフロー
 * (認可と一覧.md 4.2 節)。今日の版 = 定義の管理.md 2 節の有効な版、表示の終了日 = 次の版の開始日の前日(3.2 節)。鍵を取らない。
 *
 * @phpstan-type VersionPeriod array{number: int, startDate: string, endDate: string|null}
 * @phpstan-type FlowRow array{code: string, name: string, moduleName: string, currentVersion: VersionPeriod|null,
 *     versionCount: int, referrerSummary: string|null}
 */
class FlowDefinitionList
{
    public function __construct(
        private EffectiveFlowVersions $versions,
        private SubjectRows $subjectRows,
    ) {}

    /**
     * @return list<FlowRow> 並び = フローの並び順(sort_key・code)
     */
    public function flows(DateTimeInterface $today, string $localeCode): array
    {
        $flows = WorkflowFlow::query()->with('workflowContent.recordModule')->withCount('workflowFlowVersions')
            ->orderBy('sort_key')->orderBy('code')->get();
        $currentIds = $this->versions->idsOn(array_values($flows->modelKeys()), $today);
        $current = WorkflowFlowVersion::query()->whereKey(array_values($currentIds))->get()->keyBy('workflow_flow_id');
        $groupIds = $flows->pluck('authz_subject_group_id')
            ->filter(fn (mixed $id) => $id !== null)->map(fn (mixed $id) => (int) $id);
        $referrers = $this->subjectRows->of(array_values($groupIds->all()), $today, $localeCode);

        return array_values($flows->map(function (WorkflowFlow $flow) use ($current, $referrers) {
            $version = $current->get($flow->id);
            $names = array_column($referrers[(int) $flow->authz_subject_group_id] ?? [], 'name');

            return [
                'code' => $flow->code,
                'name' => $flow->name,
                'moduleName' => $flow->workflowContent->recordModule->name
                    ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールが無い"),
                'currentVersion' => $version === null ? null : [
                    'number' => $version->number,
                    'startDate' => $version->start_date->toDateString(),
                    'endDate' => self::displayEndDateOf($version),
                ],
                'versionCount' => (int) $flow->getAttribute('workflow_flow_versions_count'),
                'referrerSummary' => $names === [] ? null : implode('、', $names),
            ];
        })->all());
    }

    /**
     * 表示の終了日(次の版の開始日 = 表の終了日の前日。最後の版は null = 定義の管理の画面.md 3.2 節)。
     */
    public static function displayEndDateOf(WorkflowFlowVersion $version): ?string
    {
        return $version->end_date?->subDay()->toDateString();
    }
}
