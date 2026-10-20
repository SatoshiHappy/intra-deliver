<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ルートのノードを版の部品にし、ノードの設定の列と制約を足す(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.3、直列化と検査.md 5 節の (2))。
 * 63 バイトを超えて切り詰められる名前が重ならないように、複合外部キーの索引は指す列を先頭に、版の列を後ろに置く(直列化と検査.md 5 節の手当て 6)。
 */
return new class extends Migration
{
    /** 段 3a の種類の値 */
    private const KINDS = "'apply', 'approve', 'serial', 'parallel', 'branch_start', 'branch_end', 'sync_start', 'sync_end'";

    /** 処理対象者の指定を持たない種類(分岐・同期) */
    private const ROUTING_KINDS = "'branch_start', 'branch_end', 'sync_start', 'sync_end'";

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropForeign(['workflow_route_id']);
            $table->dropUnique(['workflow_route_id', 'code']);
            $table->dropUnique(['workflow_route_id', 'position']);
            $table->dropColumn('workflow_route_id');
        });

        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->foreignId('workflow_flow_version_id')->constrained()->cascadeOnDelete();
            $table->string('target_kind', 20)->nullable();
            $table->string('relative_base', 20)->nullable();
            $table->string('relative_scope', 20)->nullable();
            $table->foreignId('company_post_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->bigInteger('designator_workflow_route_node_id')->nullable();
            $table->integer('min_assignees')->nullable();
            $table->integer('max_assignees')->nullable();
            $table->string('decision_rule', 20)->nullable();
            $table->integer('decision_value')->nullable();
            $table->boolean('prevents_applicant_approval')->default(false);
            $table->bigInteger('closing_workflow_route_node_id')->nullable();
            $table->bigInteger('authz_subject_group_id')->nullable()->change();

            $table->unique(['workflow_flow_version_id', 'code']);
            $table->unique(['workflow_flow_version_id', 'position']);
            $table->unique(['workflow_flow_version_id', 'id']);
            $table->foreign(['workflow_flow_version_id', 'designator_workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->restrictOnDelete();
            $table->foreign(['workflow_flow_version_id', 'closing_workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->restrictOnDelete();
            $table->index(['designator_workflow_route_node_id', 'workflow_flow_version_id']);
            $table->index(['closing_workflow_route_node_id', 'workflow_flow_version_id']);
        });

        DB::statement('ALTER TABLE workflow_route_nodes DROP CONSTRAINT workflow_route_nodes_kind_check');

        foreach ($this->checks() as $name => $expression) {
            DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations(段 2 の形に戻す。足した列を消すと、その列を含む検査制約・索引も消える)。
     */
    public function down(): void
    {
        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropForeign(['workflow_flow_version_id', 'designator_workflow_route_node_id']);
            $table->dropForeign(['workflow_flow_version_id', 'closing_workflow_route_node_id']);
            $table->dropForeign(['workflow_flow_version_id']);
            $table->dropForeign(['company_post_id']);
        });

        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->dropColumn([
                'workflow_flow_version_id', 'target_kind', 'relative_base', 'relative_scope', 'company_post_id', 'designator_workflow_route_node_id',
                'min_assignees', 'max_assignees', 'decision_rule', 'decision_value', 'prevents_applicant_approval', 'closing_workflow_route_node_id',
            ]);
        });

        DB::statement('ALTER TABLE workflow_route_nodes DROP CONSTRAINT workflow_route_nodes_kind_check');
        DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_kind_check CHECK (kind IN ('apply', 'approve'))");

        Schema::table('workflow_route_nodes', function (Blueprint $table) {
            $table->bigInteger('authz_subject_group_id')->nullable(false)->change();
            $table->foreignId('workflow_route_id')->constrained()->cascadeOnDelete();
            $table->unique(['workflow_route_id', 'code']);
            $table->unique(['workflow_route_id', 'position']);
        });
    }

    /**
     * 足す・置き換える検査制約(名前 → 式。名前は式に出る列の順 = 共通の規則 9 節)。
     *
     * @return array<string, string>
     */
    private function checks(): array
    {
        $routing = self::ROUTING_KINDS;

        return [
            'workflow_route_nodes_kind_check' => 'kind IN ('.self::KINDS.')',
            'workflow_route_nodes_target_kind_check' => "target_kind IN ('fixed', 'relative', 'designated')",
            'workflow_route_nodes_relative_base_check' => "relative_base IN ('applicant', 'previous')",
            'workflow_route_nodes_relative_scope_check' => "relative_scope IN ('own', 'parent', 'ancestors')",
            'workflow_route_nodes_decision_rule_check' => "decision_rule IN ('all', 'count', 'ratio')",
            'workflow_route_nodes_kind_target_kind_check' => "(kind IN ({$routing})) = (target_kind IS NULL) AND (kind <> 'apply' OR target_kind = 'fixed') AND (kind NOT IN ('serial', 'parallel') OR target_kind = 'designated')",
            'workflow_route_nodes_target_kind_authz_subject_group_id_check' => "(target_kind IS NOT DISTINCT FROM 'fixed') = (authz_subject_group_id IS NOT NULL)",
            'workflow_route_nodes_target_kind_relative_base_relative_scope_company_post_id_check' => "(target_kind IS NOT DISTINCT FROM 'relative') = (relative_base IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'relative') = (relative_scope IS NOT NULL) AND (company_post_id IS NULL OR target_kind IS NOT DISTINCT FROM 'relative')",
            'workflow_route_nodes_target_kind_designator_workflow_route_node_id_min_assignees_max_assignees_check' => "(target_kind IS NOT DISTINCT FROM 'designated') = (designator_workflow_route_node_id IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'designated') = (min_assignees IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'designated') = (max_assignees IS NOT NULL)",
            'workflow_route_nodes_min_assignees_max_assignees_check' => 'min_assignees BETWEEN 0 AND 20 AND max_assignees BETWEEN 1 AND 20 AND min_assignees <= max_assignees',
            'workflow_route_nodes_kind_decision_rule_check' => "(kind = 'parallel') = (decision_rule IS NOT NULL)",
            'workflow_route_nodes_decision_rule_decision_value_check' => "(decision_rule IS NULL OR decision_rule = 'all') = (decision_value IS NULL) AND (decision_rule IS DISTINCT FROM 'ratio' OR decision_value BETWEEN 1 AND 100)",
            'workflow_route_nodes_decision_rule_decision_value_min_assignees_check' => "decision_rule <> 'count' OR decision_value BETWEEN 1 AND min_assignees",
            'workflow_route_nodes_prevents_applicant_approval_kind_check' => "NOT prevents_applicant_approval OR kind IN ('approve', 'serial', 'parallel')",
            'workflow_route_nodes_kind_closing_workflow_route_node_id_check' => "(kind IN ('branch_start', 'sync_start')) = (closing_workflow_route_node_id IS NOT NULL)",
        ];
    }
};
