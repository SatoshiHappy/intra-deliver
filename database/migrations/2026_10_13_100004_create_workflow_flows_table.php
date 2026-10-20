<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_flows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->string('description', 4000)->nullable();
            $table->foreignId('workflow_content_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('workflow_route_id')->index()->constrained()->restrictOnDelete();
            // 参照者の指定(NULL = 参照者なし)。下の (authz_subject_group_id, purpose) の外部キーで、用途 selection の対象者であることを
            // DB が止める(purpose は定数の生成列。アプリは書かず読まない = docs の 設計/ワークフロー/データモデル/表.md 2.4)
            $table->bigInteger('authz_subject_group_id')->nullable();
            $table->string('purpose', 20)->storedAs("'selection'");
            $table->bigInteger('sort_key')->default(0);
            $table->timestampsTz();

            $table->foreign(['authz_subject_group_id', 'purpose'])->references(['id', 'purpose'])->on('authz_subject_groups')->restrictOnDelete();
            $table->index(['authz_subject_group_id', 'purpose']);
        });

        DB::statement("ALTER TABLE workflow_flows ADD CONSTRAINT workflow_flows_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_flows ADD CONSTRAINT workflow_flows_name_check CHECK (name <> '')");
        DB::statement('ALTER TABLE workflow_flows ADD CONSTRAINT workflow_flows_sort_key_check CHECK (sort_key BETWEEN -999999999999999 AND 999999999999999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_flows');
    }
};
