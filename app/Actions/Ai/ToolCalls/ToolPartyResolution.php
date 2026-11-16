<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolParties;

/**
 * 引数の中のユーザー・組織の相手を引いた結果({@see ToolPartyNames})。決まらない相手があれば道具の検証の誤りの文(10.3 の B1)を持ち、
 * 無ければ名前の物を id に直した引数と、読んだ相手を持つ。
 */
final readonly class ToolPartyResolution
{
    /**
     * @param  array<string, mixed>  $arguments  名前の物を id に直した引数(誤りのときは渡した引数のまま)
     */
    public function __construct(
        public array $arguments,
        public AgentToolParties $parties,
        public ?string $error = null,
    ) {}
}
