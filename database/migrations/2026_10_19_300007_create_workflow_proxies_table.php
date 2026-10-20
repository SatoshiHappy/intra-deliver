<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 代理(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.12 節)。期間は共通の規則の半開区間の式だけを採り、同じ組の重なりを
 * 許すので一意・排他制約を置かない。行は登録と削除だけ。
 *
 * 案件の運用の最後のマイグレーション。down の先頭で、逆の順に戻す前に、保留の行・足した種別の行・実行者を持つ行が無いことを確かめる
 * (その値は前の形で表せない = 同じ文書群の README.md の 5 節)。
 */
return new class extends Migration
{
    /** 履歴の種別のうち、案件の運用で足したもの */
    private const ADDED_ACTIONS = [
        'hold', 'release', 'transfer', 'retarget', 'reexpand', 'move_back', 'move_forward', 'move_finish', 'reassign',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('principal_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('proxy_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->foreignId('workflow_flow_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('registered_by_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            // 代理先からの引き(代理の項・述語・候補。外部キー proxy_user_id の索引を兼ねる)
            $table->index(['proxy_user_id', 'kind']);
        });

        DB::statement(
            'ALTER TABLE workflow_proxies ADD CONSTRAINT workflow_proxies_kind_check '
            ."CHECK (kind IN ('apply', 'approve'))",
        );
        DB::statement(
            'ALTER TABLE workflow_proxies ADD CONSTRAINT '
            .'workflow_proxies_proxy_user_id_principal_user_id_check CHECK (proxy_user_id <> principal_user_id)',
        );
        DB::statement(
            'ALTER TABLE workflow_proxies ADD CONSTRAINT workflow_proxies_start_date_end_date_check '
            .'CHECK (isfinite(start_date) AND (end_date IS NULL OR (isfinite(end_date) AND start_date < end_date)))',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $blockers = [
            'workflow_case_nodes' => DB::table('workflow_case_nodes')->where('state', 'hold'),
            'workflow_case_histories' => DB::table('workflow_case_histories')
                ->whereIn('action', self::ADDED_ACTIONS)->orWhereNotNull('executor_user_id'),
        ];

        foreach ($blockers as $table => $rows) {
            if ($rows->exists()) {
                throw new RuntimeException("{$table} に前の形で表せない行(保留・足した種別・実行者)がある"
                    .'ので down を止めた');
            }
        }

        Schema::dropIfExists('workflow_proxies');
    }
};
