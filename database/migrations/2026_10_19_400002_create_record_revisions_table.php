<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 更新履歴の版(レコードの部品。追記だけ。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節)。
 * snapshot の中の upload_id は列でないので外部キーを持たない(同じ文書の 2.1.1)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_revisions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('record_module_id');
            $table->bigInteger('record_id');
            $table->integer('revision');
            $table->string('action', 20);
            $table->jsonb('snapshot');
            $table->jsonb('labels');
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('principal_user_id')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->string('origin_operation', 100)->nullable();
            $table->string('origin_node_name', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(['record_id', 'revision']);
            $table->foreign(['record_module_id', 'record_id'])
                ->references(['record_module_id', 'id'])->on('records')->cascadeOnDelete();
            $table->index(['record_module_id', 'record_id']);
        });

        $this->addChecks();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_revisions');
    }

    /**
     * 検査制約(名前は 63 バイトを超えるものを PostgreSQL が切り詰める = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 5 節)。
     */
    private function addChecks(): void
    {
        $table = 'ALTER TABLE record_revisions ADD CONSTRAINT record_revisions_';

        DB::statement($table.'revision_check CHECK (revision BETWEEN 1 AND 2147483647)');
        DB::statement($table."action_check CHECK (action IN ('create', 'update'))");
        DB::statement($table."action_revision_check CHECK (action <> 'create' OR revision = 1)");
        DB::statement($table."snapshot_check CHECK (jsonb_typeof(snapshot) = 'object')");
        DB::statement($table."labels_check CHECK (jsonb_typeof(labels) = 'object')");
        DB::statement($table."origin_operation_check CHECK (origin_operation <> '')");
        DB::statement($table."origin_node_name_check CHECK (origin_node_name <> '')");
        DB::statement($table.'origin_operation_origin_node_name_principal_user_id_check'
            .' CHECK (origin_operation IS NOT NULL OR (origin_node_name IS NULL AND principal_user_id IS NULL))');
        DB::statement($table.'principal_user_id_user_id_check'
            .' CHECK (principal_user_id IS NULL OR (user_id IS NOT NULL AND principal_user_id <> user_id))');
    }
};
