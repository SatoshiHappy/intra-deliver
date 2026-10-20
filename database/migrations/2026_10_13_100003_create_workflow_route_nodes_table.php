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
        Schema::create('workflow_route_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_route_id')->constrained()->cascadeOnDelete();
            $table->string('code', 100);
            $table->string('name', 1000);
            $table->string('kind', 20);
            $table->integer('position');
            $table->bigInteger('authz_subject_group_id');
            // 定数の生成列(下の (authz_subject_group_id, purpose) の外部キーで、処理対象者の指定が用途 selection の対象者であることを
            // DB が止める = docs の 設計/ワークフロー/データモデル/表.md 2.3。アプリは書かず読まない)
            $table->string('purpose', 20)->storedAs("'selection'");
            $table->timestampsTz();

            $table->unique(['workflow_route_id', 'code']);
            $table->unique(['workflow_route_id', 'position']);
            $table->foreign(['authz_subject_group_id', 'purpose'])->references(['id', 'purpose'])->on('authz_subject_groups')->restrictOnDelete();
            $table->index(['authz_subject_group_id', 'purpose']);
        });

        DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_name_check CHECK (name <> '')");
        DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_kind_check CHECK (kind IN ('apply', 'approve'))");
        DB::statement('ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_position_check CHECK (position >= 1)');
        DB::statement("ALTER TABLE workflow_route_nodes ADD CONSTRAINT workflow_route_nodes_position_kind_check CHECK ((position = 1) = (kind = 'apply'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_route_nodes');
    }
};
