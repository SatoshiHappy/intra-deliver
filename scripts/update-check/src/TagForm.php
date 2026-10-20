<?php

namespace UpdateCheck;

/**
 * タグの「同じ形」(設計/更新チェック.md 1 節の #4)。今のタグの数字の部分だけを入れ替えた形で、系列は今の値のまま。
 * 例: 1.12.7-php8.4.26-trixie に対して 1.12.8-php8.4.26-trixie・1.12.7-php8.4.27-trixie は同じ形、1.13.1-php8.5.11-trixie は違う形。
 */
final readonly class TagForm
{
    private string $regex;

    /** @var list<int> */
    private array $numbers;

    public function __construct(private string $current, ?string $seriesPattern)
    {
        $this->regex = '/^'.$this->template($current, $seriesPattern).'$/';
        $this->numbers = self::numbers($current);
    }

    public function matches(string $tag): bool
    {
        return preg_match($this->regex, $tag) === 1;
    }

    /**
     * 同じ形で今より新しいタグのうち、いちばん新しいもの(数字を左から比べる)。無ければ null。
     *
     * @param  list<string>  $tags
     */
    public function newest(array $tags): ?string
    {
        $newest = null;
        $best = $this->numbers;

        foreach ($tags as $tag) {
            if ($this->matches($tag) && self::numbers($tag) > $best) {
                $newest = $tag;
                $best = self::numbers($tag);
            }
        }

        return $newest;
    }

    /**
     * @return list<int>
     */
    public static function numbers(string $tag): array
    {
        preg_match_all('/\d+/', $tag, $matches);

        return array_map(intval(...), $matches[0]);
    }

    private function template(string $tag, ?string $seriesPattern): string
    {
        if ($seriesPattern === null || preg_match($seriesPattern, $tag, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $this->digits($tag);
        }

        [$series, $offset] = $match[1];

        return $this->digits(substr($tag, 0, $offset)).preg_quote($series, '/').$this->digits(substr($tag, $offset + strlen($series)));
    }

    private function digits(string $text): string
    {
        return (string) preg_replace('/\d+/', '\\d+', preg_quote($text, '/'));
    }
}
