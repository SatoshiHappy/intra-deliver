<?php

namespace UpdateCheck;

use DateTimeImmutable;

/**
 * endoflife.date の 1 つの系列。使う鍵だけを、使うときに確かめる(使わない系列の不備で製品ごと確認できなくしない)。
 * 鍵が要るもの: eolFrom・isEol・latest.name(eolFrom は null 可 = 期限なし)・releaseDate。無い・型が違う・日付が読めなければ確認できなかった。
 */
final readonly class EolCycle
{
    /**
     * @param  array<array-key, mixed>  $release
     */
    public function __construct(
        public string $name,
        private array $release,
        private string $what,
    ) {}

    /**
     * 期限(UTC の暦日)。null = 期限なし(鍵はあるが値が null)。
     *
     * @throws UnconfirmedException
     */
    public function eolFrom(): ?DateTimeImmutable
    {
        if (! array_key_exists('eolFrom', $this->release)) {
            throw new UnconfirmedException("{$this->what} の系列 {$this->name} に鍵 eolFrom が無い");
        }

        $value = $this->release['eolFrom'];

        if ($value === null) {
            return null;
        }

        return Dates::parse(is_string($value) ? $value : '', "{$this->what} の系列 {$this->name} の eolFrom");
    }

    /**
     * @throws UnconfirmedException
     */
    public function isEol(): bool
    {
        return Json::boolean($this->release, 'isEol', "{$this->what} の系列 {$this->name}");
    }

    /**
     * @throws UnconfirmedException
     */
    public function releaseDate(): DateTimeImmutable
    {
        return Dates::parse(Json::string($this->release, 'releaseDate', "{$this->what} の系列 {$this->name}"), "{$this->what} の系列 {$this->name} の releaseDate");
    }

    /**
     * その系列の最新の版。
     *
     * @throws UnconfirmedException
     */
    public function latest(): string
    {
        $part = "{$this->what} の系列 {$this->name}";

        return Json::string(Json::table($this->release, 'latest', $part), 'name', "{$part} の latest");
    }
}
