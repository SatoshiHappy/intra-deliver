<?php

namespace UpdateCheck;

/**
 * ファイルに書かれているイメージの参照(タグと digest)。
 */
final readonly class ImageRef
{
    public function __construct(
        public string $tag,
        public ?string $digest,
    ) {}
}
