<?php

namespace App\Actions\Reset;

use App\Queries\Reset\ResetSettings;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Queue;

/**
 * 初期化が掛けて解く停止(手順 1・3 = docs の 設計/公開の配備/毎日の初期化.md 2 節)。メンテナンスの状態は、DownCommand が
 * storage/framework/maintenance.php も書くので、メンテナンスの状態の口を直接使う。解くのは何度でもよい。
 */
class ResetStops
{
    /** メンテナンスの状態の payload に置く初期化の印のキー(値 = 記録の行の番号) */
    public const MARK = 'daily_reset_run';

    public function __construct(private ResetSettings $settings) {}

    public function pauseQueue(int $seconds): void
    {
        Queue::pauseFor($this->settings->queueConnection(), $this->settings->queueName(), $seconds);
    }

    public function resumeQueue(): void
    {
        Queue::resume($this->settings->queueConnection(), $this->settings->queueName());
    }

    public function enterMaintenance(int $runId): void
    {
        App::maintenanceMode()->activate(['status' => 503, self::MARK => $runId]);
    }

    /** 初期化の印のメンテナンスの状態だけを解く */
    public function leaveMaintenance(): void
    {
        if ($this->markedMaintenance()) {
            App::maintenanceMode()->deactivate();
        }
    }

    /** 初期化の印を付けたメンテナンスの状態か */
    public function markedMaintenance(): bool
    {
        return App::maintenanceMode()->active() && array_key_exists(self::MARK, App::maintenanceMode()->data());
    }

    /** 初期化の印の無いメンテナンスの状態か */
    public function unmarkedMaintenance(): bool
    {
        return App::maintenanceMode()->active() && ! array_key_exists(self::MARK, App::maintenanceMode()->data());
    }
}
