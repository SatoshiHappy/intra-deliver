<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * フローのルートの列と、ルートの表を消す(版がルートを持つ = docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.2・2.6、直列化と検査.md 5 節の (4))。
 * down は段 2 の形に戻す(ルートの表は 2026_10_13_100002 と同じ形。フローの列は表の末尾に付く)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workflow_flows', function (Blueprint $table) {
            $table->dropForeign(['workflow_route_id']);
            $table->dropIndex(['workflow_route_id']);
            $table->dropColumn('workflow_route_id');
        });

        Schema::dropIfExists('workflow_routes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('workflow_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name', 1000);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE workflow_routes ADD CONSTRAINT workflow_routes_code_check CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')");
        DB::statement("ALTER TABLE workflow_routes ADD CONSTRAINT workflow_routes_name_check CHECK (name <> '')");

        Schema::table('workflow_flows', function (Blueprint $table) {
            $table->foreignId('workflow_route_id')->index()->constrained()->restrictOnDelete();
        });
    }
};
