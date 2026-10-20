<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 案件の辺(申請の時に版の辺を写した行。案件の部品。docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.3)。両端は同じ案件のノード(複合外部キー)。
 * 制約はルートの辺(2026_10_16_100003)と同じ形に、分岐の評価の結果 taken の組を足す。名前の切り詰めの手当ても同じ(直列化と検査.md 5 節の手当て 3・6)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('from_workflow_case_node_id');
            $table->bigInteger('to_workflow_case_node_id');
            $table->integer('sort_key');
            $table->string('label', 1000)->nullable();
            $table->jsonb('condition')->nullable();
            $table->boolean('taken')->nullable();
            $table->timestampsTz();

            $table->unique(['from_workflow_case_node_id', 'to_workflow_case_node_id']);
            $table->foreign(['workflow_case_id', 'from_workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->foreign(['workflow_case_id', 'to_workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->index(['to_workflow_case_node_id', 'workflow_case_id']);
            $table->index(['from_workflow_case_node_id', 'workflow_case_id']);
        });

        DB::statement('ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_to_workflow_case_node_id_from_workflow_case_node_id_check CHECK (to_workflow_case_node_id <> from_workflow_case_node_id)');
        DB::statement('ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_label_condition_check CHECK ((label IS NULL) = (condition IS NULL))');
        DB::statement("ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_label_check CHECK (label <> '')");
        DB::statement("ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_condition_check CHECK (jsonb_typeof(condition) = 'object')");
        DB::statement('ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_sort_key_check CHECK (sort_key >= 0)');
        DB::statement('ALTER TABLE workflow_case_edges ADD CONSTRAINT workflow_case_edges_taken_condition_check CHECK (taken IS NULL OR condition IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_case_edges');
    }
};
