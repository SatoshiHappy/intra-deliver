<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\ToolCalls\PartyCandidates;

/**
 * 名前の引き当ての回数を数える候補の部品(引く・引かないを、結果でなく呼ばれた回数で確かめるため)。引き当ては本物に任せる。
 */
final class CountingPartyCandidates extends PartyCandidates
{
    /** @var list<string> 引いた名前(呼ばれた順) */
    public array $names = [];

    public function resolve(string $code, string $type, string $name, AgentToolSubject $subject): array
    {
        $this->names[] = $name;

        return parent::resolve($code, $type, $name, $subject);
    }
}
