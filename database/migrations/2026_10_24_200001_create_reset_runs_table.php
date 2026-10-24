<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 初期化の記録の表(docs の 設計/公開の配備/毎日の初期化.md 1 節・2 節。1 回 1 行)。毎日の初期化は、この表を空にしない。
 * その日(東京の 4:00 から翌日の 4:00 の前まで)・動かし方・始めた時刻・入れ直しの印(手順 5 のトランザクションの中で書く時刻)・
 * 成否と終わりの時刻(動いている間と落ちた回は無い)。成功の行は入れ直しの印を持つ(成功は入れ直しのコミットの後に閉じる)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reset_runs', function (Blueprint $table) {
            $table->id();
            $table->date('reset_on');
            $table->string('trigger', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('reloaded_at')->nullable();
            $table->string('result', 16)->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->index('reset_on');
        });

        DB::statement('ALTER TABLE reset_runs'
            ." ADD CONSTRAINT reset_runs_trigger_check CHECK (trigger IN ('schedule', 'manual'))");
        DB::statement('ALTER TABLE reset_runs'
            ." ADD CONSTRAINT reset_runs_result_check CHECK (result IN ('succeeded', 'failed'))");
        DB::statement('ALTER TABLE reset_runs'
            .' ADD CONSTRAINT reset_runs_result_finished_at_check CHECK ((result IS NULL) = (finished_at IS NULL))');
        DB::statement('ALTER TABLE reset_runs'
            .' ADD CONSTRAINT reset_runs_result_reloaded_at_check'
            ." CHECK (result <> 'succeeded' OR reloaded_at IS NOT NULL)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reset_runs');
    }
};
