<?php

namespace App\Actions\Ai\Core;

/**
 * 道具を動かす口の探すの返り値の種類(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の道具を動かす口)。
 */
enum ToolLookupAnswer: string
{
    case Found = 'found';

    case Missing = 'missing';

    case NoConversation = 'no_conversation';
}
