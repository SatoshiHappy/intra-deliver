<?php

namespace App\Actions\Reset;

use App\Queries\Reset\ResetSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * 初期化の鎖錠(手順 0 = docs の 設計/公開の配備/毎日の初期化.md 2 節、ADR 0024 の決定 6)。DB のセッションの鎖錠なので、
 * 表を空にしても消えず、プロセスが落ちるか接続が切れると DB が解く(見張りが後で取れる)。
 */
class ResetLock
{
    public function __construct(private ResetSettings $settings) {}

    /** 鎖錠を取る。待っても取れなければ false */
    public function acquire(): bool
    {
        $waits = $this->settings->seconds('lock_wait_seconds');

        for ($waited = 0; ; $waited++) {
            if ($this->tryLock()) {
                return true;
            }

            if ($waited >= $waits) {
                return false;
            }

            Sleep::for(1)->second();
        }
    }

    /** この接続のセッションが鎖錠を持っているか(接続が切れてつなぎ直していれば持っていない) */
    public function held(): bool
    {
        $key = $this->settings->seconds('lock_key');
        $row = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted"
            .' AND objsubid = 1 AND ((classid::bigint << 32) | objid::bigint) = ?) AS held',
            [$key],
        );

        return (bool) $row->held;
    }

    /** 持っていれば放す */
    public function release(): void
    {
        if ($this->held()) {
            DB::select('SELECT pg_advisory_unlock(?)', [$this->settings->seconds('lock_key')]);
        }
    }

    private function tryLock(): bool
    {
        $row = DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [$this->settings->seconds('lock_key')]);

        return (bool) $row->locked;
    }
}
