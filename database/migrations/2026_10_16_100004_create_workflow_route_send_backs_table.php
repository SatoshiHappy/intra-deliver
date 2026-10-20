<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 差戻しできる先(ルートのノードの部品。docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.5)。差戻し元と先は同じ版のノード(複合外部キー。版の列はその範囲)。
 * 版への外部キーを持つ(版を消す 1 文で止まらないため。辺と同じ形 = 定義の表.md 2.5、案件の表.md 3.8)。差戻し元と先が違うことの検査制約は置かない
 * (先が祖先であることを業務処理が止める)。複合外部キーの索引は差戻し元・先の列を先頭にし、版の索引は版の列だけにする(版を先頭にした複合の索引は、
 * 切り詰めた名前が差戻し元の複合外部キーと重なる = 直列化と検査.md 5 節の手当て 4・6)。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_route_send_backs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_flow_version_id')->index()->constrained()->cascadeOnDelete();
            $table->bigInteger('workflow_route_node_id');
            $table->bigInteger('target_workflow_route_node_id');
            $table->timestampsTz();

            $table->unique(['workflow_route_node_id', 'target_workflow_route_node_id']);
            $table->foreign(['workflow_flow_version_id', 'workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->cascadeOnDelete();
            $table->foreign(['workflow_flow_version_id', 'target_workflow_route_node_id'])->references(['workflow_flow_version_id', 'id'])->on('workflow_route_nodes')->restrictOnDelete();
            $table->index(['workflow_route_node_id', 'workflow_flow_version_id']);
            $table->index(['target_workflow_route_node_id', 'workflow_flow_version_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_route_send_backs');
    }
};
