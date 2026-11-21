<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 接続先の応答の状態コードを実行の行とステップの行に足す(断られた試しを使った額に 0 で数えるため = docs の 設計/AI/実行と費用/接続先と費用.md
 * 5.2)。起きない組は CHECK で止める(組 = 設計/AI/実行の記録.md 2.1・2.3)。
 */
return new class extends Migration
{
    private const RANGE = 'provider_status BETWEEN 100 AND 599';

    private const DECLINED = "(provider_status < 400 OR (status = 'failed' AND input_tokens IS NULL))";

    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->smallInteger('provider_status')->nullable();
        });
        Schema::table('ai_run_steps', function (Blueprint $table): void {
            $table->smallInteger('provider_status')->nullable();
        });

        foreach (self::checks() as $table => $checks) {
            foreach ($checks as $name => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$check})");
            }
        }
    }

    public function down(): void
    {
        foreach (self::checks() as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
            }
        }

        Schema::table('ai_run_steps', function (Blueprint $table): void {
            $table->dropColumn('provider_status');
        });
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropColumn('provider_status');
        });
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function checks(): array
    {
        return [
            'ai_runs' => [
                'ai_runs_provider_status_check' => self::RANGE,
                'ai_runs_status_provider_status_check' => "(status NOT IN ('queued', 'running')"
                    .' OR provider_status IS NULL)'
                    ." AND (kind_type <> 'conversation' OR provider_status IS NULL) AND ".self::DECLINED,
            ],
            'ai_run_steps' => [
                'ai_run_steps_provider_status_check' => self::RANGE,
                'ai_run_steps_status_provider_status_check' => "(status <> 'running' OR provider_status IS NULL)"
                    .' AND '.self::DECLINED,
            ],
        ];
    }
};
