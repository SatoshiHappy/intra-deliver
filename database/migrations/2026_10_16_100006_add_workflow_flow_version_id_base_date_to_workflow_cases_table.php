<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件が写した版と申請基準日(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.1)。写した版は案件のフローの版(複合外部キー)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_cases', function (Blueprint $table) {
            $table->bigInteger('workflow_flow_version_id')->nullable();
            $table->date('base_date')->nullable();

            $table->foreign(['workflow_flow_id', 'workflow_flow_version_id'])->references(['workflow_flow_id', 'id'])->on('workflow_flow_versions')->restrictOnDelete();
            $table->index(['workflow_flow_version_id', 'workflow_flow_id']);
        });

        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_workflow_flow_version_id_base_date_check CHECK ((state = 'draft') = (workflow_flow_version_id IS NULL) AND (state = 'draft') = (base_date IS NULL))");
        DB::statement('ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_base_date_check CHECK (isfinite(base_date))');
    }

    /**
     * Reverse the migrations(列を消すと、その列を含む検査制約・外部キー・索引も消える)。
     */
    public function down(): void
    {
        Schema::table('workflow_cases', function (Blueprint $table) {
            $table->dropColumn(['workflow_flow_version_id', 'base_date']);
        });
    }
};
