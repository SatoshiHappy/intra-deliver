<?php

namespace App\Models\Reset;

use App\Enums\Reset\ResetResult;
use App\Enums\Reset\ResetTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * 初期化の記録の表 reset_runs(docs の 設計/公開の配備/毎日の初期化.md 2 節。reloaded_at = 2 節の手順 5、result が null の行 = 4 節)。
 */
#[Fillable(['reset_on', 'trigger', 'started_at', 'reloaded_at', 'result', 'finished_at'])]
class ResetRun extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reset_on' => 'date',
            'trigger' => ResetTrigger::class,
            'started_at' => 'datetime',
            'reloaded_at' => 'datetime',
            'result' => ResetResult::class,
            'finished_at' => 'datetime',
        ];
    }
}
