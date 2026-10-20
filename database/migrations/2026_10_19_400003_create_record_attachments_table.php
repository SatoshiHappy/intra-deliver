<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * レコードの添付(レコードの部品。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.3 節)。
 * レコードへの外部キーは RESTRICT(連鎖で消えると uploads の行と実体が台帳に残るので、削除の業務処理が先に添付を消す。
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 1 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('record_attachments', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('record_module_id');
            $table->bigInteger('record_id');
            $table->foreignId('upload_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['record_module_id', 'record_id'])
                ->references(['record_module_id', 'id'])->on('records')->restrictOnDelete();
            $table->index(['record_module_id', 'record_id']);
        });

        DB::statement("ALTER TABLE record_attachments ADD CONSTRAINT record_attachments_name_check CHECK (name <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_attachments');
    }
};
