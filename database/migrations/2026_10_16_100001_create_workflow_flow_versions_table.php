<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 段 3a の最初のマイグレーション: 段 2 の定義と案件の行が無いことを確かめてから、フローの版の表を作る(docs の 設計/ワークフロー/ルートの深さ/データモデル/
 * 定義の表.md 2.1、直列化と検査.md 5 節)。段 2 の行は移さない(ルート・フロー・案件の行があれば例外で止まる)。down の確かめは段 3a の最後の
 * マイグレーション(2026_10_16_100011)の down が行う(down はそこから逆の順に走る)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['workflow_routes', 'workflow_flows', 'workflow_cases'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException("{$table} に行があるので段 3a のマイグレーションを止めた(段 2 の定義と案件の行は移さない。開発用 DB は作り直す)");
            }
        }

        Schema::create('workflow_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_flow_id')->constrained()->cascadeOnDelete();
            $table->integer('number');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestampsTz();

            $table->unique(['workflow_flow_id', 'number']);
            $table->unique(['workflow_flow_id', 'id']);
        });

        DB::statement('ALTER TABLE workflow_flow_versions ADD CONSTRAINT workflow_flow_versions_number_check CHECK (number >= 1)');
        DB::statement('ALTER TABLE workflow_flow_versions ADD CONSTRAINT workflow_flow_versions_start_date_end_date_check CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))');
        DB::statement("ALTER TABLE workflow_flow_versions ADD CONSTRAINT workflow_flow_versions_workflow_flow_id_start_date_end_date_excl EXCLUDE USING gist (workflow_flow_id WITH =, daterange(start_date, end_date, '[)') WITH &&)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_flow_versions');
    }
};
