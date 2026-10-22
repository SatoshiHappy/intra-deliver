<?php

namespace Tests\Feature\Queries\Authz;

use App\Actions\Authz\CreateResource;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Master\User;
use App\Queries\Authz\Api\Authorization;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\ResourceTypes;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\ServiceResourceTypes;
use App\Queries\Menu\MenuResourceTypes;
use App\Queries\Records\RecordModuleResourceTypes;
use App\Queries\Workflow\WorkflowFlowResourceTypes;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の公開の口(docs の 設計/ワークフロー/案件の運用/README.md の「コードのモジュール」): リソースタイプを集める口の実装 4 つがタグで集まり、
 * URI とアクションの判定が内部の判定と同じ結果を返す(許可・禁止・ポリシーなし・リソースなし)。
 */
final class AuthorizationApiTest extends TransactionalTestCase
{
    public function test_the_four_resource_type_lists_are_gathered_by_the_tag(): void
    {
        $tagged = iterator_to_array(app()->tagged(ResourceTypes::TAG), false);
        $classes = array_map(fn (ResourceTypes $types) => $types::class, $tagged);
        $flowTypes = app(WorkflowFlowResourceTypes::class)->actionsByResourceType();

        $this->assertSame([
            ServiceResourceTypes::class,
            MenuResourceTypes::class,
            RecordModuleResourceTypes::class,
            WorkflowFlowResourceTypes::class,
        ], $classes);
        $this->assertSame([WorkflowFlowAction::RESOURCE_TYPE => ['read', 'operate']], $flowTypes);
    }

    /**
     * 許可(システム管理者のフローの read)・禁止(一般の利用者)・ポリシーなし(ポリシーの無いセットのリソース)・リソースなし(無い URI)。
     */
    public function test_allows_uri_returns_the_same_result_as_the_internal_judgement(): void
    {
        $date = CarbonImmutable::parse('2026-10-13');
        $names = ['ja' => ['display_name' => '試し']];
        app(CreateResource::class)->execute('test-no-policy', null, 'service', 'service://test/no-policy', $names);
        $flowUri = WorkflowFlowAction::uri('project_plan');
        $cases = [
            ['u0004', $flowUri, 'read', true],
            ['u0004', $flowUri, 'operate', true],
            ['u0001', $flowUri, 'read', false],
            ['u0004', 'service://test/no-policy', 'execute', false],
            ['u0004', 'workflow-flow://flow/no-such-flow', 'read', false],
        ];

        foreach ($cases as [$userCode, $uri, $action, $expected]) {
            $requester = new Requester(User::query()->where('code', $userCode)->sole()->id, null);
            $internal = app(AuthorizationQuery::class)->allowsUri($requester, $uri, $action, $date);
            $public = app(Authorization::class)->allowsUri($requester, $uri, $action, $date);

            $this->assertSame([$expected, $expected], [$internal, $public], "{$userCode} {$uri} {$action}");
        }
    }
}
