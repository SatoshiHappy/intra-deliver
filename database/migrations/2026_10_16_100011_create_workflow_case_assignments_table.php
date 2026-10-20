<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 指定(承認の時に指定した処理対象者。案件の部品。追記だけ。docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.5)。指定した承認の履歴の行と
 * 指定されたノードは同じ案件のもの(複合外部キー)。2 つの一意制約の列の順は名前の切り詰めのため(直列化と検査.md 5 節の手当て 5)。
 *
 * 段 3a の最後のマイグレーション。down の先頭で、段 3a のマイグレーションを逆の順に戻す前に、ルート・フロー・案件の行が無いことを確かめる
 * (段 3a の定義と案件は段 2 の形で表せない = 直列化と検査.md 5 節。up の確かめは最初のマイグレーション 2026_10_16_100001)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_case_history_id');
            $table->bigInteger('workflow_case_node_id');
            $table->integer('sequence');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['workflow_case_node_id', 'workflow_case_history_id', 'sequence']);
            $table->unique(['user_id', 'workflow_case_history_id', 'workflow_case_node_id']);
            $table->foreign(['workflow_case_id', 'workflow_case_history_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_histories')->restrictOnDelete();
            $table->foreign(['workflow_case_id', 'workflow_case_node_id'])->references(['workflow_case_id', 'id'])->on('workflow_case_nodes')->restrictOnDelete();
            $table->index(['workflow_case_node_id', 'workflow_case_id']);
            $table->index(['workflow_case_history_id', 'workflow_case_id']);
        });

        DB::statement('ALTER TABLE workflow_case_assignments ADD CONSTRAINT workflow_case_assignments_sequence_check CHECK (sequence >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['workflow_flows', 'workflow_cases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} に行があるので段 3a のマイグレーションの down を止めた(段 3a の定義と案件は段 2 の形で表せない)");
            }
        }

        Schema::dropIfExists('workflow_case_assignments');
    }
};
