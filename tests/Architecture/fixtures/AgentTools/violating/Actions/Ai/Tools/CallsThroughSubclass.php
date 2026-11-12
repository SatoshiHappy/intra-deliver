<?php

namespace App\Actions\Ai\Tools;

/**
 * 許した口の型を継いだ型の受け手でも、主体の判定をしない操作は禁止
 */
final class CallsThroughSubclass
{
    public function __construct(private SneakyDisplayQuery $display) {}

    public function title(int $recordId): ?string
    {
        return $this->display->titleOf($recordId);
    }
}
