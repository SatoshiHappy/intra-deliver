<?php

namespace App\Actions\Ai\Core;

/**
 * 確かめの口(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3、位置 = docs の 設計/AI/会話の中核.md 13.5)。受け手と中核の受け持ちは
 * 設計/AI/会話の中核の口と受け手.md 13.3 の確かめの口の項。
 */
interface CoreChecker
{
    public function check(CheckRequest $request): CheckReply;
}
