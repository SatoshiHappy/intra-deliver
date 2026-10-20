<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の表と、案件番号のシーケンス(docs の 設計/ワークフロー/データモデル/表.md 2.5、直列化と検査.md 4 節)。
 * シーケンスは case_number の持ち物(OWNED BY)で、表を消すと一緒に消える。列の既定値にはしない(一時保存は番号を持たず、
 * 申請の確定の時だけ業務処理が nextval を呼ぶ)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_flow_id')->index()->constrained()->restrictOnDelete();
            $table->string('state', 20);
            $table->string('completion_reason', 20)->nullable();
            $table->bigInteger('case_number')->nullable()->unique();
            $table->string('name', 1000)->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('record_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->jsonb('draft_input')->nullable();
            $table->integer('revision')->default(0);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'state']);
        });

        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_check CHECK (state IN ('draft', 'active', 'completed'))");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_completion_reason_check CHECK (completion_reason IN ('approved', 'approved_early', 'rejected', 'withdrawn'))");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_completion_reason_completed_at_check CHECK ((state = 'completed') = (completion_reason IS NOT NULL) AND (state = 'completed') = (completed_at IS NOT NULL))");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_record_id_case_number_applied_at_check CHECK ((state = 'draft') = (record_id IS NULL) AND (state = 'draft') = (case_number IS NULL) AND (state = 'draft') = (applied_at IS NULL))");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_name_draft_input_check CHECK ((state = 'draft') = (name IS NULL) AND (state = 'draft') = (draft_input IS NOT NULL))");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_state_department_id_check CHECK (state <> 'draft' OR department_id IS NULL)");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_name_check CHECK (name <> '')");
        DB::statement("ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_draft_input_check CHECK (jsonb_typeof(draft_input) = 'object')");
        DB::statement('ALTER TABLE workflow_cases ADD CONSTRAINT workflow_cases_revision_check CHECK (revision >= 0)');
        DB::statement("CREATE UNIQUE INDEX workflow_cases_record_id_unique ON workflow_cases (record_id) WHERE state = 'active'");
        DB::statement('CREATE SEQUENCE workflow_case_number_seq START 1 INCREMENT 1 NO CYCLE OWNED BY workflow_cases.case_number');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS workflow_case_number_seq');
        Schema::dropIfExists('workflow_cases');
    }
};
