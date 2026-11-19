<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use Tests\Unit\Actions\Ai\Core\Contract\SendContractTestCase;

/**
 * 代わりの版で流す約束のテスト(docs の 設計/AI/検査で守る結果.md の中核と繋ぎの項)。
 */
final class SubstituteSendContractTest extends SendContractTestCase
{
    protected function core(): ConversationCore
    {
        return new SubstituteConversationCore;
    }
}
