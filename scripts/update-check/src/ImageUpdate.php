<?php

namespace UpdateCheck;

/**
 * 固定したイメージ 1 つの更新の調べの結果(#4)。#5 が新しい土台を決めるのにも使う。
 */
final readonly class ImageUpdate
{
    public function __construct(
        public string $written,
        public string $tag,
        public string $pinnedDigest,
        public ?string $newestTag,
        public ?string $newestDigest,
        public string $currentDigest,
    ) {}

    public function digestChanged(): bool
    {
        return $this->currentDigest !== $this->pinnedDigest;
    }

    public function hasUpdate(): bool
    {
        return $this->newestTag !== null || $this->digestChanged();
    }

    /**
     * 新しい土台(名前:タグ@digest)。同じ形の最新タグがあればそれ、無ければ固定したタグが今指す digest が変わったときのそれ。どちらも無ければ null。
     */
    public function newBase(): ?string
    {
        if ($this->newestTag !== null && $this->newestDigest !== null) {
            return $this->written.':'.$this->newestTag.'@'.$this->newestDigest;
        }

        return $this->digestChanged() ? $this->written.':'.$this->tag.'@'.$this->currentDigest : null;
    }
}
