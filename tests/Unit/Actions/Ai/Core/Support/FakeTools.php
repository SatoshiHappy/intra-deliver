<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\CreatedFileMark;
use App\Actions\Ai\Core\CreatedFilesReader;
use App\Actions\Ai\Core\ToolLookup;
use App\Actions\Ai\Core\ToolRun;
use App\Actions\Ai\Core\ToolRunner;
use App\Actions\Ai\Core\ToolRuns;
use Closure;
use DateTimeImmutable;

/**
 * 中核のテストの道具を動かす口と作ったファイルの読み手(名前 ask の道具は問う、ほかは呼び出しの名前を本文にした結果を返す)。
 */
final class FakeTools implements CreatedFilesReader, ToolRunner
{
    /** @var list<string> */
    public array $ran = [];

    /** @var (Closure(): (DateTimeImmutable|null))|null 動かす間の処理(返り値 = 道具の中で成功した最後の送信の完了の時刻) */
    public ?Closure $during = null;

    /**
     * @param  list<CreatedFileMark>  $files
     */
    public function __construct(private array $files = []) {}

    public function find(CoreToolCall $call, bool $preRun): ToolLookup
    {
        return ToolLookup::found();
    }

    public function run(CoreToolCall $call, bool $preRun): ToolRun
    {
        $this->ran[] = $call->callId;
        $finishedAt = $this->during === null ? null : ($this->during)();
        $result = ConversationItem::toolResult($call->callId, null, [], "done:{$call->name}", false);

        return $call->name === 'ask' ? ToolRun::asks() : ToolRun::result($result, $finishedAt);
    }

    /** @var list<list<string>> 並べて動かした組(呼び出しの id) */
    public array $groups = [];

    public function runParallel(array $calls, int $concurrency): ToolRuns
    {
        $this->groups[] = array_map(static fn (CoreToolCall $call): string => $call->callId, $calls);

        return ToolRuns::results(array_map(fn (CoreToolCall $call): ToolRun => $this->run($call, false), $calls));
    }

    public function forCompaction(): array
    {
        return $this->files;
    }
}
