<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Master\FillsAllLocales;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Workflow\WorkflowFlow;

/**
 * workflow-flow のセットとフローごとのリソースを作る 1 か所(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節)。初期データとテストの支えが共有する。
 * Authz の内部の業務処理を通して作り(行を直に書かない)、ID・URI は {@see WorkflowFlowAction} の組み立てを使う。
 * ワークフローの業務処理・読み取りは Authz の作る口に依存しないので、seeder の側に置く。
 */
final class WorkflowFlowResources
{
    use FillsAllLocales;
    use FindsAuthzSubjects;

    public function __construct(
        private CreateResourceGroup $createResourceGroup,
        private CreateResource $createResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    /**
     * セット workflow-flows と、その先頭のシステム管理者への read・operate の許可(全部を許すロールの規則 = docs の 設計/メニュー/初期データ.md の 4 節)。
     * フローのリソースを作る前に 1 回呼ぶ。
     */
    public function createSet(): void
    {
        $names = ['ja' => ['display_name' => 'ワークフローのフロー'], 'en' => ['display_name' => 'Workflow flows']];
        $set = $this->createResourceGroup->execute(WorkflowFlowAction::SET_CODE, null, $names);
        $administrator = $this->systemAdministratorSubjectGroupId($this->findOrCreate);

        $type = WorkflowFlowAction::RESOURCE_TYPE;

        foreach (WorkflowFlowAction::cases() as $action) {
            $this->createPolicy->execute($set->id, $administrator, $type, $action->value, PolicyEffect::Permit);
        }
    }

    /**
     * フローのリソースグループとリソース(親 = セット、表示名 = すべてのロケールにフローの名前)。フローを作る業務処理の後に呼ぶ。
     */
    public function createFor(string $flowCode): void
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->sole();
        $set = AuthzResourceGroup::query()->where('code', WorkflowFlowAction::SET_CODE)->sole();
        $names = array_map(fn (string $name) => ['display_name' => $name], $this->forAllLocales($flow->name));

        $this->createResource->execute(
            WorkflowFlowAction::resourceGroupCode($flowCode),
            $set->id,
            WorkflowFlowAction::RESOURCE_TYPE,
            WorkflowFlowAction::uri($flowCode),
            $names,
        );
    }
}
