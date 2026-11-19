<?php

namespace Tests\Unit\Actions\Ai\Conversation;

use App\Actions\Ai\Conversation\CoreVersion;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * 版を選ぶ 1 か所の既定は代わりの版(docs の 設計/AI/会話の中核.md 13.1)。
 */
final class CoreVersionTest extends TestCase
{
    public function test_the_default_is_the_substitute_version(): void
    {
        $container = new Container;

        CoreVersion::bindDefault($container);

        $this->assertInstanceOf(SubstituteConversationCore::class, $container->make(ConversationCore::class));
    }
}
