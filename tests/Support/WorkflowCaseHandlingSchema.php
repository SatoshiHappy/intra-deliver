<?php

namespace Tests\Support;

use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowProxy;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Model;

/**
 * 案件の運用で足したワークフローの 4 表の設計どおりの列と、案件の運用で足した外部キー(既存の表に足した列のものを含む)
 * (docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節・3 節)。既存の表に足した列は {@see WorkflowSchema} の
 * COLUMNS_ADDED_LATER、制約と索引の名前は {@see WorkflowConstraintNames}。列の書き方は {@see WorkflowSchema} と同じ。
 */
final class WorkflowCaseHandlingSchema
{
    private const KEY = 'bigint not null';

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'workflow_case_history_users' => WorkflowCaseHistoryUser::class,
        'workflow_case_confirm_targets' => WorkflowCaseConfirmTarget::class,
        'workflow_case_confirmations' => WorkflowCaseConfirmation::class,
        'workflow_proxies' => WorkflowProxy::class,
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'workflow_case_history_users' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_history_id' => self::KEY,
            'sequence' => 'integer not null',
            'user_id' => self::KEY,
        ],
        'workflow_case_confirm_targets' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_node_id' => self::KEY,
            'user_id' => self::KEY,
        ],
        'workflow_case_confirmations' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_node_id' => self::KEY,
            'workflow_case_history_id' => self::KEY,
            'user_id' => self::KEY,
        ],
        'workflow_proxies' => [
            'principal_user_id' => self::KEY,
            'proxy_user_id' => self::KEY,
            'kind' => 'character varying(20) not null',
            'workflow_flow_id' => 'bigint',
            'start_date' => 'date not null',
            'end_date' => 'date',
            'registered_by_user_id' => self::KEY,
        ],
    ];

    /**
     * 外部キー(書き方は {@see WorkflowSchema::FOREIGN_KEYS} と同じ)。参照先の側のリレーションは、使い手がまだ無いので null。
     * 確認から確認対象者への (workflow_case_node_id, user_id) は参照先を id で指さないので、参照する側のリレーションは
     * 同じ人を指す user で確かめる。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [WorkflowRouteNode::class, 'confirming', ['confirm_authz_subject_group_id', 'purpose'], 'authz_subject_groups',
            ['id', 'purpose'], 'restrict', 'confirmAuthzSubjectGroup', null],
        [WorkflowCaseNode::class, 'confirming', ['confirm_authz_subject_group_id', 'purpose'], 'authz_subject_groups',
            ['id', 'purpose'], 'restrict', 'confirmAuthzSubjectGroup', null],
        [WorkflowCaseNode::class, 'withPrevious', ['previous_user_id'], 'users',
            ['id'], 'restrict', 'previousUser', null],
        [WorkflowCaseNode::class, 'withPrevious', ['previous_department_id'], 'departments',
            ['id'], 'restrict', 'previousDepartment', null],
        [WorkflowCaseHistory::class, 'byProxy', ['executor_user_id'], 'users',
            ['id'], 'restrict', 'executor', null],
        [WorkflowCaseHistoryUser::class, null, ['workflow_case_id'], 'workflow_cases',
            ['id'], 'cascade', 'workflowCase', null],
        [WorkflowCaseHistoryUser::class, null, ['workflow_case_id', 'workflow_case_history_id'],
            'workflow_case_histories', ['workflow_case_id', 'id'], 'restrict', 'workflowCaseHistory', null],
        [WorkflowCaseHistoryUser::class, null, ['user_id'], 'users',
            ['id'], 'restrict', 'user', null],
        [WorkflowCaseConfirmTarget::class, null, ['workflow_case_id'], 'workflow_cases',
            ['id'], 'cascade', 'workflowCase', null],
        [WorkflowCaseConfirmTarget::class, null, ['workflow_case_id', 'workflow_case_node_id'], 'workflow_case_nodes',
            ['workflow_case_id', 'id'], 'cascade', 'workflowCaseNode', null],
        [WorkflowCaseConfirmTarget::class, null, ['user_id'], 'users',
            ['id'], 'restrict', 'user', null],
        [WorkflowCaseConfirmation::class, null, ['workflow_case_id'], 'workflow_cases',
            ['id'], 'cascade', 'workflowCase', null],
        [WorkflowCaseConfirmation::class, null, ['workflow_case_id', 'workflow_case_node_id'], 'workflow_case_nodes',
            ['workflow_case_id', 'id'], 'restrict', 'workflowCaseNode', null],
        [WorkflowCaseConfirmation::class, null, ['workflow_case_id', 'workflow_case_history_id'],
            'workflow_case_histories', ['workflow_case_id', 'id'], 'restrict', 'workflowCaseHistory', null],
        [WorkflowCaseConfirmation::class, null, ['workflow_case_node_id', 'user_id'], 'workflow_case_confirm_targets',
            ['workflow_case_node_id', 'user_id'], 'restrict', 'user', null],
        [WorkflowCaseConfirmation::class, null, ['user_id'], 'users',
            ['id'], 'restrict', 'user', null],
        [WorkflowProxy::class, null, ['principal_user_id'], 'users',
            ['id'], 'restrict', 'principalUser', null],
        [WorkflowProxy::class, null, ['proxy_user_id'], 'users',
            ['id'], 'restrict', 'proxyUser', null],
        [WorkflowProxy::class, 'forFlow', ['workflow_flow_id'], 'workflow_flows',
            ['id'], 'restrict', 'workflowFlow', null],
        [WorkflowProxy::class, null, ['registered_by_user_id'], 'users',
            ['id'], 'restrict', 'registeredByUser', null],
    ];
}
