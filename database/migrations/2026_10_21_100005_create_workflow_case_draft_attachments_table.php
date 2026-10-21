<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一時保存の添付(一時保存の案件の部品。docs の 設計/ワークフロー/申請の周辺/データモデル.md の 3.2 節)。案件へは CASCADE、
 * 実体(uploads)へは RESTRICT(同じ文書の 3.3 節)。行は追記と削除だけ。
 *
 * 添付の列と表を足すマイグレーションの最後。down の先頭で、逆の順に戻す前に、足した値(使わないにしたフロー・写しが false の案件・
 * 添付を追加できないノード・一時保存の添付の行)が無いことを確かめる(その値は前の形で表せない)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_case_draft_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_case_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('upload_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE workflow_case_draft_attachments ADD CONSTRAINT workflow_case_draft_attachments_name_check '
            ."CHECK (name <> '')",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $blockers = [
            'workflow_flows' => DB::table('workflow_flows')->where('is_attachment_enabled', false),
            'workflow_cases' => DB::table('workflow_cases')->where('is_attachment_enabled', false),
            'workflow_route_nodes' => DB::table('workflow_route_nodes')->where('prevents_attachment_add', true),
            'workflow_case_nodes' => DB::table('workflow_case_nodes')->where('prevents_attachment_add', true),
            'workflow_case_draft_attachments' => DB::table('workflow_case_draft_attachments'),
        ];

        foreach ($blockers as $table => $rows) {
            if ($rows->exists()) {
                throw new RuntimeException("{$table} に前の形で表せない行(添付を使わない・追加できない・一時保存の添付)がある"
                    .'ので down を止めた');
            }
        }

        Schema::dropIfExists('workflow_case_draft_attachments');
    }
};
