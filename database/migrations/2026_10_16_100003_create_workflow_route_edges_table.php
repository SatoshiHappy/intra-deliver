<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ルートの辺(版の部品。docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.4)。両端は同じ版のノード(複合外部キー)。
 * 63 バイトを超えて切り詰められる名前が重ならないように、両端が違うことの検査制約は to_… <> from_… の順に書き、複合外部キーの索引は端の列を先頭にする
 * (直列化と検査.md 5 節の手当て 3・6)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_route_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_flow_version_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('from_workflow_route_node_id');
            $table->bigInteger('to_workflow_route_node_id');
            $table->integer('sort_key')->default(0);
            $table->string('label', 1000)->nullable();
            $table->jsonb('condition')->nullable();
            $table->timestampsTz();

            $table->unique(['from_workflow_route_node_id', 'to_workflow_route_node_id']);
            $table->foreign(['workflow_flow_version_id', 'from_workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->restrictOnDelete();
            $table->foreign(['workflow_flow_version_id', 'to_workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->restrictOnDelete();
            $table->index(['to_workflow_route_node_id', 'workflow_flow_version_id']);
            $table->index(['from_workflow_route_node_id', 'workflow_flow_version_id']);
        });

        DB::statement('ALTER TABLE workflow_route_edges ADD CONSTRAINT workflow_route_edges_to_workflow_route_node_id_from_workflow_route_node_id_check CHECK (to_workflow_route_node_id <> from_workflow_route_node_id)');
        DB::statement('ALTER TABLE workflow_route_edges ADD CONSTRAINT workflow_route_edges_label_condition_check CHECK ((label IS NULL) = (condition IS NULL))');
        DB::statement("ALTER TABLE workflow_route_edges ADD CONSTRAINT workflow_route_edges_label_check CHECK (label <> '')");
        DB::statement("ALTER TABLE workflow_route_edges ADD CONSTRAINT workflow_route_edges_condition_check CHECK (jsonb_typeof(condition) = 'object')");
        DB::statement('ALTER TABLE workflow_route_edges ADD CONSTRAINT workflow_route_edges_sort_key_check CHECK (sort_key >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_route_edges');
    }
};
