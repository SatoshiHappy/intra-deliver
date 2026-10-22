<?php

namespace Tests\Feature\Seeders;

use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Master\Locale;
use App\Models\Workflow\WorkflowFlow;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの初期データの workflow-flow(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節・6 節): セット workflow-flows の下に、
 * どのフローにもフローのリソースグループ(ID・親・すべてのロケールにフローの名前)とリソース(タイプ・URI)がある。
 */
final class WorkflowSeederFlowResourcesTest extends TransactionalTestCase
{
    public function test_the_set_has_its_display_names_and_no_parent(): void
    {
        $set = AuthzResourceGroup::query()->with('authzResourceGroupTranslations.locale')
            ->where('code', WorkflowFlowAction::SET_CODE)->sole();

        $this->assertNull($set->parent_id);
        $this->assertSame(['en' => 'Workflow flows', 'ja' => 'ワークフローのフロー'], $this->displayNames($set));
        $this->assertFalse(AuthzResource::query()->where('authz_resource_group_id', $set->id)->exists());
    }

    public function test_every_flow_has_its_resource_group_and_resource_under_the_set(): void
    {
        $setId = AuthzResourceGroup::query()->where('code', WorkflowFlowAction::SET_CODE)->sole()->id;
        $locales = Locale::query()->orderBy('code')->pluck('code')->all();
        $flows = WorkflowFlow::query()->orderBy('code')->get();

        $this->assertSame(['issue_resolution', 'project_plan'], $flows->pluck('code')->all());

        foreach ($flows as $flow) {
            $group = AuthzResourceGroup::query()->with('authzResourceGroupTranslations.locale')
                ->where('code', 'workflow-flow-'.$flow->code)->sole();
            $resource = AuthzResource::query()->where('authz_resource_group_id', $group->id)->sole();

            $this->assertSame($setId, $group->parent_id, $flow->code);
            $this->assertSame(array_fill_keys($locales, $flow->name), $this->displayNames($group), $flow->code);
            $expected = ['workflow-flow', "workflow-flow://flow/{$flow->code}"];
            $this->assertSame($expected, [$resource->resource_type, $resource->uri], $flow->code);
        }
    }

    /**
     * @return array<string, string> ロケールのコード → 表示名(コードの順)
     */
    private function displayNames(AuthzResourceGroup $group): array
    {
        $names = [];

        foreach ($group->authzResourceGroupTranslations as $translation) {
            /** @var AuthzResourceGroupTranslation $translation */
            $names[(string) $translation->locale?->code] = $translation->display_name;
        }

        ksort($names);

        return $names;
    }
}
