<?php

namespace Tests\Support;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\Model;

/**
 * ワークフローの 13 表の設計どおりの列・外部キーと、制約・索引の名前(docs の 設計/ワークフロー/データモデル/表.md 2 節と、段 3a の差 = 設計/ワークフロー/ルートの深さ/
 * データモデル/定義の表.md 2 節・案件の表.md 3 節。型は 設計/ワークフロー/データモデル/README.md 1 節で採った共通の規則 4 節、名前は 設計/ワークフロー/データモデル/直列化と検査.md 4 節と、
 * 設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md 5 節)。
 * 列の書き方は {@see RecordsSchema} と同じ(列 → PostgreSQL の format_type と NOT NULL・既定値。id・created_at・updated_at は SchemaTest が足す。
 * 生成列は「generated always as 式 stored」で、式は pg_get_expr の形 = 定数は括弧なし)。段 3a で既存の表に足した列は {@see self::COLUMNS_ADDED_LATER}(表の末尾)。
 */
final class WorkflowSchema
{
    private const CODE = 'character varying(100) not null';

    private const NAME = 'character varying(1000) not null';

    private const KEY = 'bigint not null';

    private const OPTIONAL_KEY = 'bigint';

    private const VALUE_LIST = 'character varying(20) not null';

    private const OPTIONAL_VALUE_LIST = 'character varying(20)';

    private const POSITION = 'integer not null';

    private const OPTIONAL_INTEGER = 'integer';

    private const TIMESTAMP = 'timestamp(0) with time zone';

    /** 複合外部キーで用途 selection の対象者だけを指すための定数の生成列 */
    private const SELECTION_PURPOSE = "character varying(20) not null generated always as 'selection'::character varying stored";

    /** ノードの設定の列(ルートのノードと案件のノードで同じ。指すノードの列は表ごと) */
    private const NODE_SETTINGS = [
        'target_kind' => self::OPTIONAL_VALUE_LIST,
        'relative_base' => self::OPTIONAL_VALUE_LIST,
        'relative_scope' => self::OPTIONAL_VALUE_LIST,
        'company_post_id' => self::OPTIONAL_KEY,
    ];

    /** ノードの設定の列のうち、指定するノードの後の列 */
    private const NODE_SETTINGS_AFTER_DESIGNATOR = [
        'min_assignees' => self::OPTIONAL_INTEGER,
        'max_assignees' => self::OPTIONAL_INTEGER,
        'decision_rule' => self::OPTIONAL_VALUE_LIST,
        'decision_value' => self::OPTIONAL_INTEGER,
        'prevents_applicant_approval' => 'boolean not null default false',
    ];

    /** 処理期限の日数と確認する人(案件の運用で足したステップの設定の列。ルートのノードと案件のノードで同じ) */
    private const NODE_DEADLINE_CONFIRM = [
        'deadline_days' => self::OPTIONAL_INTEGER,
        'confirm_authz_subject_group_id' => self::OPTIONAL_KEY,
    ];

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'workflow_contents' => WorkflowContent::class,
        'workflow_flows' => WorkflowFlow::class,
        'workflow_flow_versions' => WorkflowFlowVersion::class,
        'workflow_route_nodes' => WorkflowRouteNode::class,
        'workflow_route_edges' => WorkflowRouteEdge::class,
        'workflow_route_send_backs' => WorkflowRouteSendBack::class,
        'workflow_cases' => WorkflowCase::class,
        'workflow_case_nodes' => WorkflowCaseNode::class,
        'workflow_case_edges' => WorkflowCaseEdge::class,
        'workflow_case_send_backs' => WorkflowCaseSendBack::class,
        'workflow_case_node_targets' => WorkflowCaseNodeTarget::class,
        'workflow_case_histories' => WorkflowCaseHistory::class,
        'workflow_case_assignments' => WorkflowCaseAssignment::class,
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ: 参照する側のモデル、factory の状態、列、参照先の表と列、
     * 削除の動作、参照する側のリレーション、参照先の側のリレーション)。参照先のモジュールからワークフローへ依存できないとき・同じ向きのリレーションが
     * 別の行にあるとき・参照先の側に使い手の無いときは参照先の側のリレーションを null にする。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}>
     */
    public const FOREIGN_KEYS = [
        [WorkflowContent::class, null, ['record_module_id', 'is_detail'], 'record_modules', ['id', 'is_detail'], 'restrict', 'recordModule', null],
        [WorkflowFlow::class, null, ['workflow_content_id'], 'workflow_contents', ['id'], 'restrict', 'workflowContent', 'workflowFlows'],
        [WorkflowFlow::class, 'withReferrers', ['authz_subject_group_id', 'purpose'], 'authz_subject_groups', ['id', 'purpose'], 'restrict', 'referrers', null],
        [WorkflowFlowVersion::class, null, ['workflow_flow_id'], 'workflow_flows', ['id'], 'cascade', 'workflowFlow', 'workflowFlowVersions'],
        [WorkflowRouteNode::class, null, ['workflow_flow_version_id'], 'workflow_flow_versions', ['id'], 'cascade', 'workflowFlowVersion', 'workflowRouteNodes'],
        [WorkflowRouteNode::class, null, ['authz_subject_group_id', 'purpose'], 'authz_subject_groups', ['id', 'purpose'], 'restrict', 'authzSubjectGroup', null],
        [WorkflowRouteNode::class, 'relative', ['company_post_id'], 'company_posts', ['id'], 'restrict', 'companyPost', null],
        [WorkflowRouteNode::class, 'designated', ['workflow_flow_version_id', 'designator_workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'restrict', 'designator', null],
        [WorkflowRouteNode::class, 'branchStart', ['workflow_flow_version_id', 'closing_workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'restrict', 'closing', null],
        [WorkflowRouteEdge::class, null, ['workflow_flow_version_id'], 'workflow_flow_versions', ['id'], 'cascade', 'workflowFlowVersion', 'workflowRouteEdges'],
        [WorkflowRouteEdge::class, null, ['workflow_flow_version_id', 'from_workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'restrict', 'fromNode', 'outgoingEdges'],
        [WorkflowRouteEdge::class, null, ['workflow_flow_version_id', 'to_workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'restrict', 'toNode', 'incomingEdges'],
        [WorkflowRouteSendBack::class, null, ['workflow_flow_version_id'], 'workflow_flow_versions', ['id'], 'cascade', 'workflowFlowVersion', null],
        [WorkflowRouteSendBack::class, null, ['workflow_flow_version_id', 'workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'cascade', 'workflowRouteNode', 'workflowRouteSendBacks'],
        [WorkflowRouteSendBack::class, null, ['workflow_flow_version_id', 'target_workflow_route_node_id'], 'workflow_route_nodes', ['workflow_flow_version_id', 'id'], 'restrict', 'targetNode', null],
        [WorkflowCase::class, null, ['workflow_flow_id'], 'workflow_flows', ['id'], 'restrict', 'workflowFlow', 'workflowCases'],
        [WorkflowCase::class, 'active', ['workflow_flow_id', 'workflow_flow_version_id'], 'workflow_flow_versions', ['workflow_flow_id', 'id'], 'restrict', 'workflowFlowVersion', 'workflowCases'],
        [WorkflowCase::class, null, ['user_id'], 'users', ['id'], 'restrict', 'applicant', null],
        [WorkflowCase::class, 'active', ['department_id'], 'departments', ['id'], 'restrict', 'department', null],
        [WorkflowCase::class, 'active', ['record_id'], 'records', ['id'], 'restrict', 'record', null],
        [WorkflowCaseNode::class, null, ['workflow_case_id'], 'workflow_cases', ['id'], 'cascade', 'workflowCase', 'workflowCaseNodes'],
        [WorkflowCaseNode::class, null, ['authz_subject_group_id', 'purpose'], 'authz_subject_groups', ['id', 'purpose'], 'restrict', 'authzSubjectGroup', null],
        [WorkflowCaseNode::class, 'done', ['id', 'workflow_case_history_id'], 'workflow_case_histories', ['workflow_case_node_id', 'id'], 'restrict', 'workflowCaseHistory', null],
        [WorkflowCaseNode::class, 'relative', ['company_post_id'], 'company_posts', ['id'], 'restrict', 'companyPost', null],
        [WorkflowCaseNode::class, 'designated', ['workflow_case_id', 'designator_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'designator', null],
        [WorkflowCaseNode::class, 'branchStart', ['workflow_case_id', 'closing_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'closing', null],
        [WorkflowCaseNode::class, 'child', ['workflow_case_id', 'parent_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'parent', 'children'],
        [WorkflowCaseEdge::class, null, ['workflow_case_id'], 'workflow_cases', ['id'], 'cascade', 'workflowCase', 'workflowCaseEdges'],
        [WorkflowCaseEdge::class, null, ['workflow_case_id', 'from_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'fromNode', null],
        [WorkflowCaseEdge::class, null, ['workflow_case_id', 'to_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'toNode', null],
        [WorkflowCaseSendBack::class, null, ['workflow_case_id'], 'workflow_cases', ['id'], 'cascade', 'workflowCase', null],
        [WorkflowCaseSendBack::class, null, ['workflow_case_id', 'workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'cascade', 'workflowCaseNode', 'workflowCaseSendBacks'],
        [WorkflowCaseSendBack::class, null, ['workflow_case_id', 'target_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'targetNode', null],
        [WorkflowCaseNodeTarget::class, null, ['workflow_case_node_id'], 'workflow_case_nodes', ['id'], 'cascade', 'workflowCaseNode', 'workflowCaseNodeTargets'],
        [WorkflowCaseNodeTarget::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
        [WorkflowCaseHistory::class, null, ['workflow_case_id'], 'workflow_cases', ['id'], 'cascade', 'workflowCase', 'workflowCaseHistories'],
        [WorkflowCaseHistory::class, null, ['workflow_case_id', 'workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'workflowCaseNode', 'workflowCaseHistories'],
        [WorkflowCaseHistory::class, 'sendBack', ['workflow_case_id', 'send_back_workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'sendBackWorkflowCaseNode', null],
        [WorkflowCaseHistory::class, null, ['user_id'], 'users', ['id'], 'restrict', 'actor', null],
        [
            WorkflowCaseHistory::class, null, ['record_revision_id'], 'record_revisions', ['id'], 'restrict',
            'recordRevision', null,
        ],
        [WorkflowCaseHistory::class, 'pullBack', ['workflow_case_id', 'reverted_workflow_case_history_id'], 'workflow_case_histories', ['workflow_case_id', 'id'], 'restrict', 'revertedHistory', null],
        [WorkflowCaseAssignment::class, null, ['workflow_case_id'], 'workflow_cases', ['id'], 'cascade', 'workflowCase', 'workflowCaseAssignments'],
        [WorkflowCaseAssignment::class, null, ['workflow_case_id', 'workflow_case_history_id'], 'workflow_case_histories', ['workflow_case_id', 'id'], 'restrict', 'workflowCaseHistory', null],
        [WorkflowCaseAssignment::class, null, ['workflow_case_id', 'workflow_case_node_id'], 'workflow_case_nodes', ['workflow_case_id', 'id'], 'restrict', 'workflowCaseNode', null],
        [WorkflowCaseAssignment::class, null, ['user_id'], 'users', ['id'], 'restrict', 'user', null],
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'workflow_contents' => [
            'code' => self::CODE,
            'name' => self::NAME,
            'record_module_id' => self::KEY,
            'is_detail' => 'boolean not null generated always as false stored',
        ],
        'workflow_flows' => [
            'code' => self::CODE,
            'name' => self::NAME,
            'description' => 'character varying(4000)',
            'workflow_content_id' => self::KEY,
            'authz_subject_group_id' => self::OPTIONAL_KEY,
            'purpose' => self::SELECTION_PURPOSE,
            'sort_key' => "bigint not null default '0'::bigint",
        ],
        'workflow_flow_versions' => [
            'workflow_flow_id' => self::KEY,
            'number' => 'integer not null',
            'start_date' => 'date not null',
            'end_date' => 'date',
            'is_deleted' => 'boolean not null default false',
        ],
        'workflow_route_nodes' => [
            'code' => self::CODE,
            'name' => self::NAME,
            'kind' => self::VALUE_LIST,
            'position' => self::POSITION,
            'authz_subject_group_id' => self::OPTIONAL_KEY,
            'purpose' => self::SELECTION_PURPOSE,
        ],
        'workflow_route_edges' => [
            'workflow_flow_version_id' => self::KEY,
            'from_workflow_route_node_id' => self::KEY,
            'to_workflow_route_node_id' => self::KEY,
            'sort_key' => 'integer not null default 0',
            'label' => 'character varying(1000)',
            'condition' => 'jsonb',
        ],
        'workflow_route_send_backs' => [
            'workflow_flow_version_id' => self::KEY,
            'workflow_route_node_id' => self::KEY,
            'target_workflow_route_node_id' => self::KEY,
        ],
        'workflow_cases' => [
            'workflow_flow_id' => self::KEY,
            'state' => self::VALUE_LIST,
            'completion_reason' => 'character varying(20)',
            'case_number' => 'bigint',
            'name' => 'character varying(1000)',
            'user_id' => self::KEY,
            'department_id' => self::OPTIONAL_KEY,
            'record_id' => self::OPTIONAL_KEY,
            'draft_input' => 'jsonb',
            'revision' => 'integer not null default 0',
            'applied_at' => self::TIMESTAMP,
            'completed_at' => self::TIMESTAMP,
        ],
        'workflow_case_nodes' => [
            'workflow_case_id' => self::KEY,
            'position' => self::OPTIONAL_INTEGER,
            'code' => 'character varying(100)',
            'name' => self::NAME,
            'kind' => self::VALUE_LIST,
            'authz_subject_group_id' => self::OPTIONAL_KEY,
            'purpose' => self::SELECTION_PURPOSE,
            'state' => self::VALUE_LIST,
            'workflow_case_history_id' => self::OPTIONAL_KEY,
        ],
        'workflow_case_edges' => [
            'workflow_case_id' => self::KEY,
            'from_workflow_case_node_id' => self::KEY,
            'to_workflow_case_node_id' => self::KEY,
            'sort_key' => 'integer not null',
            'label' => 'character varying(1000)',
            'condition' => 'jsonb',
            'taken' => 'boolean',
        ],
        'workflow_case_send_backs' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_node_id' => self::KEY,
            'target_workflow_case_node_id' => self::KEY,
        ],
        'workflow_case_node_targets' => ['workflow_case_node_id' => self::KEY, 'user_id' => self::KEY],
        'workflow_case_histories' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_node_id' => self::KEY,
            'action' => self::VALUE_LIST,
            'user_id' => self::KEY,
            'comment' => 'character varying(4000)',
            'send_back_workflow_case_node_id' => self::OPTIONAL_KEY,
        ],
        'workflow_case_assignments' => [
            'workflow_case_id' => self::KEY,
            'workflow_case_history_id' => self::KEY,
            'workflow_case_node_id' => self::KEY,
            'sequence' => 'integer not null',
            'user_id' => self::KEY,
        ],
    ];

    /**
     * 段 3a で既存の表に足した列(表 → 列。表の末尾に付く = SchemaTest の COLUMNS_ADDED_LATER)。案件の運用で足した列はその後ろ
     * (設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節・3.2 節・3.7 節。足した表は {@see WorkflowCaseHandlingSchema})。
     * 履歴の版の参照 record_revision_id は最後(設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS_ADDED_LATER = [
        'workflow_route_nodes' => [
            'workflow_flow_version_id' => self::KEY,
            ...self::NODE_SETTINGS,
            'designator_workflow_route_node_id' => self::OPTIONAL_KEY,
            ...self::NODE_SETTINGS_AFTER_DESIGNATOR,
            'closing_workflow_route_node_id' => self::OPTIONAL_KEY,
            ...self::NODE_DEADLINE_CONFIRM,
        ],
        'workflow_cases' => [
            'workflow_flow_version_id' => self::OPTIONAL_KEY,
            'base_date' => 'date',
        ],
        'workflow_case_nodes' => [
            ...self::NODE_SETTINGS,
            'designator_workflow_case_node_id' => self::OPTIONAL_KEY,
            ...self::NODE_SETTINGS_AFTER_DESIGNATOR,
            'closing_workflow_case_node_id' => self::OPTIONAL_KEY,
            'parent_workflow_case_node_id' => self::OPTIONAL_KEY,
            'sequence' => self::OPTIONAL_INTEGER,
            'reached_at' => self::TIMESTAMP,
            'previous_user_id' => self::OPTIONAL_KEY,
            'previous_department_id' => self::OPTIONAL_KEY,
            ...self::NODE_DEADLINE_CONFIRM,
        ],
        'workflow_case_histories' => [
            'node_changes' => 'jsonb',
            'reverted_workflow_case_history_id' => self::OPTIONAL_KEY,
            'executor_user_id' => self::OPTIONAL_KEY,
            'record_revision_id' => self::OPTIONAL_KEY,
        ],
    ];
}
