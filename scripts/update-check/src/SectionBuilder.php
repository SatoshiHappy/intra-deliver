<?php

namespace UpdateCheck;

use Closure;
use Throwable;

/**
 * 1 節の行と「確認できなかった」を集める。部分ごとに独立に判定し、1 つが確認できなくても残りを続ける(設計/更新チェック.md 5 節)。
 */
final class SectionBuilder
{
    /** @var list<Row> */
    private array $rows = [];

    /** @var list<Unconfirmed> */
    private array $unconfirmed = [];

    /**
     * @param  list<string>  $headers
     */
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly array $headers,
    ) {}

    /**
     * 部分(subject)を調べる。work が返した行を足し、どの例外でも確認できなかったに足して残りを続ける。
     *
     * @param  Closure(): list<Row>  $work
     */
    public function attempt(string $subject, Closure $work): void
    {
        try {
            foreach ($work() as $row) {
                $this->rows[] = $row;
            }
        } catch (Throwable $exception) {
            $this->unconfirmed[] = Unconfirmeds::from($subject, $exception);
        }
    }

    public function unconfirmed(string $subject, string $reason): void
    {
        $this->unconfirmed[] = new Unconfirmed($subject, $reason);
    }

    public function build(): Section
    {
        return new Section($this->id, $this->title, $this->headers, $this->rows, $this->unconfirmed);
    }
}
