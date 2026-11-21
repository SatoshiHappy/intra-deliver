<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use Tests\Unit\Actions\Ai\Core\Contract\ThinkingContractTestCase;

/**
 * 代わりの版で流す思考の文脈の管理の約束のテスト(docs の 設計/AI/検査で守る結果.md の中核の項)。
 */
final class SubstituteThinkingContractTest extends ThinkingContractTestCase
{
    protected function core(): ConversationCore
    {
        return new SubstituteConversationCore;
    }
}
