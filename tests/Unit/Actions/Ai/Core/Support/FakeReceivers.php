<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\CoreChecker;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\NoticeReply;
use Closure;

/**
 * 中核のテストの確かめの口と知らせの口(確かめは位置ごとの台本で答え、無ければ続ける。送る前はステップの番号を 1 から振る。知らせは控えて
 * 受ける)。
 */
final class FakeReceivers implements CoreChecker, CoreNotifier
{
    /** @var list<CheckRequest> */
    public array $checks = [];

    /** @var list<CoreNotice> */
    public array $notices = [];

    private int $steps = 0;

    /**
     * @param  Closure(CheckRequest): (CheckReply|null)|null  $answer  位置への答え(null を返せば既定の続ける)
     * @param  Closure(CoreNotice): (NoticeReply|null)|null  $reply  知らせへの答え(null を返せば受けた)
     */
    public function __construct(private ?Closure $answer = null, private ?Closure $reply = null) {}

    public function check(CheckRequest $request): CheckReply
    {
        $this->checks[] = $request;
        $answer = $this->answer === null ? null : ($this->answer)($request);

        if ($answer !== null) {
            return $answer;
        }

        return match ($request->point) {
            CheckPoint::BeforeSend => CheckReply::proceedToSend(++$this->steps),
            CheckPoint::BeforeRetryWait => CheckReply::proceedToWait(null),
            default => CheckReply::proceed(),
        };
    }

    public function notify(CoreNotice $notice): NoticeReply
    {
        $this->notices[] = $notice;

        return ($this->reply === null ? null : ($this->reply)($notice)) ?? NoticeReply::accepted();
    }

    /**
     * 控えた知らせのうち、その型の物。
     *
     * @template T of CoreNotice
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function noticesOf(string $class): array
    {
        $matched = array_filter($this->notices, static fn (CoreNotice $notice): bool => $notice instanceof $class);

        return array_values($matched);
    }
}
