<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件のノードに到達日時・到達の前処理者・起点の組織と、処理期限の日数・確認する人の写しを足し、状態に保留を足す
 * (docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。今の行を埋める範囲は同じ文書群の README.md の 5 節。
 */
return new class extends Migration
{
    /** 保留を足す前の状態の値 */
    private const STATES = "'unreached', 'waiting', 'reapply', 'done', 'running', 'passed', 'skipped'";

    /** 足した列(down で消す) */
    private const ADDED_COLUMNS = [
        'reached_at', 'previous_user_id', 'previous_department_id', 'deadline_days', 'confirm_authz_subject_group_id',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->timestampTz('reached_at')->nullable();
            $table->foreignId('previous_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('previous_department_id')->nullable()->index()
                ->constrained('departments')->restrictOnDelete();
            $table->integer('deadline_days')->nullable();
            $table->bigInteger('confirm_authz_subject_group_id')->nullable();

            $table->foreign(['confirm_authz_subject_group_id', 'purpose'])
                ->references(['id', 'purpose'])->on('authz_subject_groups')->restrictOnDelete();
            $table->index(['confirm_authz_subject_group_id', 'purpose']);
        });

        // 置き換える制約は在ることが前提なので IF EXISTS を付けない(名前の食い違いを黙って通さない)
        foreach ($this->previousChecks() as [$name]) {
            DB::statement("ALTER TABLE workflow_case_nodes DROP CONSTRAINT {$name}");
        }

        foreach ($this->checks() as [$name, $expression]) {
            DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        $this->fillReachedAt();
        $this->fillPrevious();

        // 現在のノード(承認待ち・再申請待ち・保留)は到達日時を持つ(埋めた後に足す)
        DB::statement(
            'ALTER TABLE workflow_case_nodes ADD CONSTRAINT workflow_case_nodes_state_reached_at_check '
            ."CHECK (state NOT IN ('waiting', 'reapply', 'hold') OR reached_at IS NOT NULL)",
        );
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約・索引も消える)。保留の行があるときは、案件の運用の最後の
     * マイグレーション(2026_10_19_300007)の down が先に止める。
     */
    public function down(): void
    {
        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->dropForeign(['previous_user_id']);
            $table->dropForeign(['previous_department_id']);
            $table->dropForeign(['confirm_authz_subject_group_id', 'purpose']);
        });

        Schema::table('workflow_case_nodes', function (Blueprint $table) {
            $table->dropColumn(self::ADDED_COLUMNS);
        });

        foreach ($this->previousChecks() as [$name, $expression]) {
            DB::statement("ALTER TABLE workflow_case_nodes DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE workflow_case_nodes ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * 足す・置き換える検査制約([名前, 式]。名前は式に出る列の順 = docs の 設計/共通マスタ/共通の規則.md の 9 節)。
     * 期限と確認する人の式はルートのノードと同じで、展開の行は確認する人を持たない。
     *
     * @return list<array{string, string}>
     */
    private function checks(): array
    {
        return [
            ['workflow_case_nodes_state_check', 'state IN ('.self::STATES.", 'hold')"],
            [
                'workflow_case_nodes_kind_state_check',
                self::kindState("'unreached', 'waiting', 'hold', 'done', 'skipped'"),
            ],
            ['workflow_case_nodes_deadline_days_check', 'deadline_days BETWEEN 0 AND 99'],
            [
                'workflow_case_nodes_deadline_days_kind_check',
                "deadline_days IS NULL OR kind IN ('approve', 'serial', 'parallel')",
            ],
            [
                'workflow_case_nodes_confirm_authz_subject_group_id_kind_check',
                "confirm_authz_subject_group_id IS NULL OR kind IN ('apply', 'approve')",
            ],
            [
                'workflow_case_nodes_parent_workflow_case_node_id_confirm_authz_subject_group_id_check',
                'parent_workflow_case_node_id IS NULL OR confirm_authz_subject_group_id IS NULL',
            ],
        ];
    }

    /**
     * 置き換える検査制約の前の式(up で消し、down で戻す)。
     *
     * @return list<array{string, string}>
     */
    private function previousChecks(): array
    {
        return [
            ['workflow_case_nodes_state_check', 'state IN ('.self::STATES.')'],
            ['workflow_case_nodes_kind_state_check', self::kindState("'unreached', 'waiting', 'done', 'skipped'")],
        ];
    }

    /**
     * 種類と状態の組の式(承認のノードの状態の一覧だけが変わる)。
     */
    private static function kindState(string $approveStates): string
    {
        return "(kind = 'apply' AND state IN ('unreached', 'reapply', 'done'))"
            ." OR (kind = 'approve' AND state IN ({$approveStates}))"
            ." OR (kind IN ('serial', 'parallel') AND state IN ('unreached', 'running', 'passed', 'skipped'))"
            ." OR (kind IN ('branch_start', 'branch_end', 'sync_start', 'sync_end')"
            ." AND state IN ('unreached', 'passed', 'skipped'))";
    }

    /**
     * 現在のノードでないノードも埋めるのは、後の引戻しがどのノードも現在のノードへ戻しうるため
     * (設計/ワークフロー/案件の運用/データモデル/README.md の 5 節)。
     */
    private function fillReachedAt(): void
    {
        DB::statement(<<<'SQL'
            UPDATE workflow_case_nodes AS n
               SET reached_at = COALESCE((
                       SELECT h.created_at
                         FROM workflow_case_histories AS h
                        WHERE h.workflow_case_id = n.workflow_case_id
                          AND (h.node_changes @> jsonb_build_array(jsonb_build_object('node', n.id))
                               OR (h.action = 'pull_back' AND h.workflow_case_node_id = n.id))
                        ORDER BY h.id DESC
                        LIMIT 1
                   ), c.applied_at)
              FROM workflow_cases AS c
             WHERE c.id = n.workflow_case_id
               AND c.state <> 'draft'
            SQL);
    }

    /**
     * 前処理者と起点の組織を、到達を起こした履歴の行から埋める(設計/ワークフロー/案件の運用/データモデル/README.md の 5 節)。
     * 差戻しの行は後ろからの到達(設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)なので使わない。
     */
    private function fillPrevious(): void
    {
        DB::statement(<<<'SQL'
            UPDATE workflow_case_nodes AS n
               SET previous_user_id = p.user_id,
                   previous_department_id = p.department_id
              FROM (
                    SELECT DISTINCT ON (x.id)
                           x.id AS node_id,
                           h.user_id,
                           CASE WHEN h.action = 'apply' THEN c.department_id END AS department_id
                      FROM workflow_case_nodes AS x
                      JOIN workflow_cases AS c ON c.id = x.workflow_case_id AND c.state <> 'draft'
                      JOIN workflow_case_histories AS h
                        ON h.workflow_case_id = x.workflow_case_id
                       AND h.node_changes @> jsonb_build_array(jsonb_build_object('node', x.id, 'state', 'unreached'))
                       AND h.action <> 'send_back'
                     ORDER BY x.id, h.id DESC
                   ) AS p
             WHERE n.id = p.node_id
            SQL);
    }
};
