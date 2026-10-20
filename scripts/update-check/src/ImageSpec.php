<?php

namespace UpdateCheck;

/**
 * 固定しているイメージ 1 つ。
 */
final readonly class ImageSpec
{
    /**
     * @param  string  $written  ファイルに書かれているイメージ名(レジストリを省いた形も含む)
     * @param  string  $path  レジストリでのパス(Docker Hub の公式イメージは library/ 付き)
     * @param  list<string>  $files  イメージを書いているファイル(アプリのルート起点)
     * @param  string|null  $seriesPattern  タグの中の系列(PHP の 8.4・PostgreSQL の 18・Node の 22)を第 1 グループで示す正規表現。系列は今の値のまま
     */
    public function __construct(
        public string $label,
        public string $written,
        public Registry $registry,
        public string $path,
        public array $files,
        public ?string $seriesPattern,
    ) {}
}
