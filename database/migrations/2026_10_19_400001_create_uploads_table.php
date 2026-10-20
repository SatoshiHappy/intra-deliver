<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * アップロードの台帳(1 行 = 実体 1 つ。docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.2 節)。
 * 期限の削除の 2 列は、表がまだ無いので表を作る時から持つ(設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 5 節)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->string('path', 100)->unique();
            $table->bigInteger('size');
            $table->string('mime', 100);
            $table->foreignId('user_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->timestampTz('purged_at')->nullable();
            $table->date('purge_due_on')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_path_check CHECK (path <> '')");
        DB::statement('ALTER TABLE uploads ADD CONSTRAINT uploads_size_check CHECK (size >= 1)');
        DB::statement("ALTER TABLE uploads ADD CONSTRAINT uploads_mime_check CHECK (mime <> '')");
        DB::statement('ALTER TABLE uploads ADD CONSTRAINT uploads_purged_at_purge_due_on_check'
            .' CHECK ((purged_at IS NULL) = (purge_due_on IS NULL))');
    }

    /**
     * Reverse the migrations(実体のファイルは消さない)。
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
