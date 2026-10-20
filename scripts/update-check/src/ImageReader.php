<?php

namespace UpdateCheck;

/**
 * ファイルに書かれた「名前:タグ@digest」を読む。同じイメージを書く場所は同じ値にそろえる(設計/更新チェック.md 8 節)。
 */
final readonly class ImageReader
{
    public function __construct(private string $root) {}

    /**
     * @throws UnconfirmedException 書かれていない・場所によって値が違う
     */
    public function read(ImageSpec $spec): ImageRef
    {
        $found = [];

        foreach ($spec->files as $file) {
            $text = @file_get_contents($this->root.'/'.$file);

            if ($text === false) {
                throw new UnconfirmedException("ファイルを読めない: {$file}");
            }

            $found = [...$found, ...$this->refs($spec->written, $text)];
        }

        $distinct = array_unique(array_map(static fn (ImageRef $ref): string => $ref->tag.'@'.($ref->digest ?? ''), $found));

        if ($found === []) {
            throw new UnconfirmedException("{$spec->written} の参照が見つからない");
        }

        if (count($distinct) > 1) {
            throw new UnconfirmedException("{$spec->written} の書かれた場所で値が違う: ".implode(' / ', $distinct));
        }

        return $found[0];
    }

    /**
     * @return list<ImageRef>
     */
    private function refs(string $written, string $text): array
    {
        $pattern = '#(?<![A-Za-z0-9_/.-])'.preg_quote($written, '#').':([A-Za-z0-9._-]+)(?:@(sha256:[0-9a-f]{64}))?#';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $match): ImageRef => new ImageRef($match[1], ($match[2] ?? '') === '' ? null : $match[2]),
            $matches,
        );
    }
}
