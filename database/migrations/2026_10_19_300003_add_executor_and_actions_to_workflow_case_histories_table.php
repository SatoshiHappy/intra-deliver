<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 履歴に実行者を足し、種別の値と、差戻し先を持つ種別(差戻しと戻る)の検査制約を置き換える
 * (docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.7 節)。実行者を持てるのは管理の種別でない行だけ。
 */
return new class extends Migration
{
    /** 前の種別(2 段目の値) */
    private const PREVIOUS_ACTIONS = "'apply', 'reapply', 'approve', 'approve_early', 'reject', "
        ."'send_back', 'pull_back', 'withdraw'";

    /** 実行者を持てる種別(案件操作は代理で行わない = 管理者は本人だけ) */
    private const EXECUTABLE_ACTIONS = self::PREVIOUS_ACTIONS.", 'hold', 'release', 'transfer'";

    /** 置き換える検査制約([名前, 前の式]。up で消し、down で前の式に戻す) */
    private const REPLACED = [
        ['workflow_case_histories_action_check', 'action IN ('.self::PREVIOUS_ACTIONS.')'],
        [
            'workflow_case_histories_action_send_back_workflow_case_node_id_check',
            "(action = 'send_back') = (send_back_workflow_case_node_id IS NOT NULL)",
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->foreignId('executor_user_id')->nullable()->index()
                ->constrained('users')->restrictOnDelete();
        });

        // 置き換える制約は在ることが前提なので IF EXISTS を付けない(名前の食い違いを黙って通さない)
        foreach (self::REPLACED as [$name]) {
            DB::statement("ALTER TABLE workflow_case_histories DROP CONSTRAINT {$name}");
        }

        foreach ($this->checks() as [$name, $expression]) {
            DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約・索引も消える)。足した種別の行があるときは、案件の運用の最後の
     * マイグレーション(2026_10_19_300007)の down が先に止める。
     */
    public function down(): void
    {
        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropForeign(['executor_user_id']);
        });

        Schema::table('workflow_case_histories', function (Blueprint $table) {
            $table->dropColumn('executor_user_id');
        });

        foreach (self::REPLACED as [$name, $expression]) {
            DB::statement("ALTER TABLE workflow_case_histories DROP CONSTRAINT {$name}");
            DB::statement("ALTER TABLE workflow_case_histories ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    /**
     * 足す・置き換える検査制約([名前, 式]。名前は式に出る列の順 = docs の 設計/共通マスタ/共通の規則.md の 9 節)。
     *
     * @return list<array{string, string}>
     */
    private function checks(): array
    {
        $actions = self::EXECUTABLE_ACTIONS
            .", 'retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish', 'reassign'";

        return [
            ['workflow_case_histories_action_check', "action IN ({$actions})"],
            [
                'workflow_case_histories_action_send_back_workflow_case_node_id_check',
                "(action IN ('send_back', 'move_back')) = (send_back_workflow_case_node_id IS NOT NULL)",
            ],
            ['workflow_case_histories_executor_user_id_user_id_check', 'executor_user_id <> user_id'],
            [
                'workflow_case_histories_executor_user_id_action_check',
                'executor_user_id IS NULL OR action IN ('.self::EXECUTABLE_ACTIONS.')',
            ],
        ];
    }
};
