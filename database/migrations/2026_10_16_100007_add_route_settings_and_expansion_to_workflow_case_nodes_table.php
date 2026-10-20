<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件のノードに、ルートのノードの設定の写しと展開の行の列を足し、状態と種類の検査制約を置き換え、現在のノードを案件に 1 つまでにする部分一意索引を消す
 * (docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.2、直列化と検査.md 5 節の (6))。展開の行は位置・コードを持たない(NULL)。
 * 写しの検査制約はルートのノードと同じ式(列の名前の route を case に替える)で、種類と指定の種類の組だけが展開の行を除く。
 */
return new class extends Migration
{
    /** 足した列(down で消す) */
    private const ADDED_COLUMNS = [
        'target_kind', 'relative_base', 'relative_scope', 'company_post_id', 'designator_workflow_case_node_id', 'min_assignees', 'max_assignees',
        'decision_rule', 'decision_value', 'prevents_applicant_approval', 'closing_workflow_case_node_id', 'parent_workflow_case_node_id', 'sequence',
    ];

    /** 同じ案件のノードを指す列(複合外部キー) */
    private const NODE_REFERENCES = ['parent_workflow_case_node_id', 'designator_workflow_case_node_id', 'closing_workflow_case_node_id'];

    /** 置き換える段 2 の検査制約(名前 → 段 2 の式) */
    private const REPLACED = [
        'workflow_case_nodes_kind_check' => "kind IN ('apply', 'approve')",
        'workflow_case_nodes_state_check' => "state IN ('unreached', 'waiting', 'reapply', 'done')",
        'workflow_case_nodes_kind_state_check' => "(kind = 'apply' AND state IN ('unreached', 'reapply', 'done')) OR (kind = 'approve' AND state IN ('unreached', 'waiting', 'done'))",
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('DROP INDEX workflow_case_nodes_workflow_case_id_unique');

        foreach (array_keys(self::REPLACED) as $name) {
            DB::statement("ALTER TABLE workflow_case_nodes DROP CONSTRAINT {$name}");
        }

        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->integer('position')->nullable()->change();
            $table->string('code', 100)->nullable()->change();
            $table->bigInteger('authz_subject_group_id')->nullable()->change();
            $table->string('target_kind', 20)->nullable();
            $table->string('relative_base', 20)->nullable();
            $table->string('relative_scope', 20)->nullable();
            $table->foreignId('company_post_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->bigInteger('designator_workflow_case_node_id')->nullable();
            $table->integer('min_assignees')->nullable();
            $table->integer('max_assignees')->nullable();
            $table->string('decision_rule', 20)->nullable();
            $table->integer('decision_value')->nullable();
            $table->boolean('prevents_applicant_approval')->default(false);
            $table->bigInteger('closing_workflow_case_node_id')->nullable();
            $table->bigInteger('parent_workflow_case_node_id')->nullable();
            $table->integer('sequence')->nullable();

            $table->unique(['parent_workflow_case_node_id', 'sequence']);

            foreach (self::NODE_REFERENCES as $column) {
                $table->foreign(['workflow_case_id', $column])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
                $table->index([$column, 'workflow_case_id']);
            }

            $table->index(['workflow_case_id', 'state']);
        });

        foreach ($this->checks() as $name => $expression) {
            DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations(段 2 の形に戻す。足した列を消すと、その列を含む検査制約・索引も消える)。
     */
    public function down(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            foreach (self::NODE_REFERENCES as $column) {
                $table->dropForeign(['workflow_case_id', $column]);
            }

            $table->dropForeign(['company_post_id']);
            $table->dropIndex(['workflow_case_id', 'state']);
        });

        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->dropColumn(self::ADDED_COLUMNS);
        });

        foreach (self::REPLACED as $name => $expression) {
            DB::statement("ALTER TABLE workflow_case_nodes DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->integer('position')->nullable(false)->change();
            $table->string('code', 100)->nullable(false)->change();
            $table->bigInteger('authz_subject_group_id')->nullable(false)->change();
        });

        DB::statement("CREATE UNIQUE INDEX workflow_case_nodes_workflow_case_id_unique ON workflow_case_nodes (workflow_case_id) WHERE state IN ('waiting', 'reapply')");
    }

    /**
     * 足す・置き換える検査制約(名前 → 式。名前は式に出る列の順 = 共通の規則 9 節。展開の行は parent_workflow_case_node_id を持つ)。
     *
     * @return array<string, string>
     */
    private function checks(): array
    {
        $routing = "'branch_start', 'branch_end', 'sync_start', 'sync_end'";

        return [
            'workflow_case_nodes_kind_check' => "kind IN ('apply', 'approve', 'serial', 'parallel', {$routing})",
            'workflow_case_nodes_state_check' => "state IN ('unreached', 'waiting', 'reapply', 'done', 'running', 'passed', 'skipped')",
            'workflow_case_nodes_kind_state_check' => "(kind = 'apply' AND state IN ('unreached', 'reapply', 'done')) OR (kind = 'approve' AND state IN ('unreached', 'waiting', 'done', 'skipped'))"
                ." OR (kind IN ('serial', 'parallel') AND state IN ('unreached', 'running', 'passed', 'skipped')) OR (kind IN ({$routing}) AND state IN ('unreached', 'passed', 'skipped'))",
            'workflow_case_nodes_target_kind_check' => "target_kind IN ('fixed', 'relative', 'designated')",
            'workflow_case_nodes_relative_base_check' => "relative_base IN ('applicant', 'previous')",
            'workflow_case_nodes_relative_scope_check' => "relative_scope IN ('own', 'parent', 'ancestors')",
            'workflow_case_nodes_decision_rule_check' => "decision_rule IN ('all', 'count', 'ratio')",
            'workflow_case_nodes_parent_workflow_case_node_id_sequence_code_position_check' => '(parent_workflow_case_node_id IS NULL) = (sequence IS NULL) AND (parent_workflow_case_node_id IS NULL) = (code IS NOT NULL) AND (parent_workflow_case_node_id IS NULL) = (position IS NOT NULL)',
            'workflow_case_nodes_sequence_check' => 'sequence >= 1',
            'workflow_case_nodes_parent_workflow_case_node_id_kind_target_kind_check' => "CASE WHEN parent_workflow_case_node_id IS NULL THEN (kind IN ({$routing})) = (target_kind IS NULL) AND (kind <> 'apply' OR target_kind = 'fixed') AND (kind NOT IN ('serial', 'parallel') OR target_kind = 'designated') ELSE kind = 'approve' AND target_kind IS NULL END",
            'workflow_case_nodes_target_kind_authz_subject_group_id_check' => "(target_kind IS NOT DISTINCT FROM 'fixed') = (authz_subject_group_id IS NOT NULL)",
            'workflow_case_nodes_target_kind_relative_base_relative_scope_company_post_id_check' => "(target_kind IS NOT DISTINCT FROM 'relative') = (relative_base IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'relative') = (relative_scope IS NOT NULL) AND (company_post_id IS NULL OR target_kind IS NOT DISTINCT FROM 'relative')",
            'workflow_case_nodes_target_kind_designator_workflow_case_node_id_min_assignees_max_assignees_check' => "(target_kind IS NOT DISTINCT FROM 'designated') = (designator_workflow_case_node_id IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'designated') = (min_assignees IS NOT NULL) AND (target_kind IS NOT DISTINCT FROM 'designated') = (max_assignees IS NOT NULL)",
            'workflow_case_nodes_min_assignees_max_assignees_check' => 'min_assignees BETWEEN 0 AND 20 AND max_assignees BETWEEN 1 AND 20 AND min_assignees <= max_assignees',
            'workflow_case_nodes_kind_decision_rule_check' => "(kind = 'parallel') = (decision_rule IS NOT NULL)",
            'workflow_case_nodes_decision_rule_decision_value_check' => "(decision_rule IS NULL OR decision_rule = 'all') = (decision_value IS NULL) AND (decision_rule IS DISTINCT FROM 'ratio' OR decision_value BETWEEN 1 AND 100)",
            'workflow_case_nodes_decision_rule_decision_value_min_assignees_check' => "decision_rule <> 'count' OR decision_value BETWEEN 1 AND min_assignees",
            'workflow_case_nodes_prevents_applicant_approval_kind_check' => "NOT prevents_applicant_approval OR kind IN ('approve', 'serial', 'parallel')",
            'workflow_case_nodes_kind_closing_workflow_case_node_id_check' => "(kind IN ('branch_start', 'sync_start')) = (closing_workflow_case_node_id IS NOT NULL)",
        ];
    }
};
