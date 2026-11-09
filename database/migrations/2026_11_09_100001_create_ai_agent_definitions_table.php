<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI のエージェントの定義の表(docs の 設計/AI/設定とプロンプト/README.md 11.1、設計/AI/実行の記録.md 2 節)。会話の型の実行が指す(RESTRICT)。
 * 道具は道具の名前の配列、ナレッジは業務モジュールのコードの配列(合わない値は実行の始めに外す = 11.3 なので、DB は配列であることだけを止める)。
 * 名前と指示文の長さの上限は定義の口が持つ(値は人の判断で決める)。指示文は空でよい。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_agent_definitions', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->text('instructions');
            $table->jsonb('tools');
            $table->jsonb('knowledge');
            $table->timestampsTz();
        });

        $checks = [
            'ai_agent_definitions_name_check' => "name <> ''",
            'ai_agent_definitions_tools_check' => "jsonb_typeof(tools) = 'array'",
            'ai_agent_definitions_knowledge_check' => "jsonb_typeof(knowledge) = 'array'",
        ];

        foreach ($checks as $name => $check) {
            DB::statement("ALTER TABLE ai_agent_definitions ADD CONSTRAINT {$name} CHECK ({$check})");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_definitions');
    }
};
