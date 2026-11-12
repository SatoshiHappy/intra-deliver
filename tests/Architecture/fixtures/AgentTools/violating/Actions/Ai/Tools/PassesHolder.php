<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Support\AccessContext;
use Closure;

/**
 * 持ち主の鍵を渡す更新は禁止(位置でも名前つきでも。callable で指すと渡す引数を決められない)
 */
final class PassesHolder
{
    public function __construct(private UpdateRecord $update) {}

    public function positional(AccessContext $context, string $holder): void
    {
        $this->update->execute($context, 'tasks', 1, new RecordInput, null, $holder, new RecordRevisionOrigin('AI'));
    }

    public function named(AccessContext $context): void
    {
        $this->update->execute($context, 'tasks', 1, new RecordInput, version: null, holder: 'workflow');
    }

    public function closure(): Closure
    {
        return $this->update->execute(...);
    }
}
