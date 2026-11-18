<?php

namespace App\Actions\Ai\Core;

/**
 * 応答の終わりの理由(docs の 設計/AI/会話の中核.md 13.6。SDK が接続先ごとに寄せた値を、約束が SDK の型に依らずに写した物。値は SDK と同じ)。
 */
enum ResponseFinish: string
{
    case Stop = 'stop';

    case ToolCalls = 'tool_calls';

    case Continue = 'continue';

    case Length = 'length';

    case ContentFilter = 'content_filter';

    case Error = 'error';

    case Cancelled = 'cancelled';

    case Unknown = 'unknown';
}
