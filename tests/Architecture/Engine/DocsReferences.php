<?php

namespace Tests\Architecture\Engine;

/**
 * 参照の実在の照合(汎用)。web のファイルの参照の語({@see ReferenceWords})が 1 つの文書に決まり、その文書・節・引用が在るか
 * (解決の順と確かめること = docs の 設計/コード構成/検査.md 6 節)。違反の種類の名前 = unresolved・ambiguous・missing・section・quote・history。
 *
 * @phpstan-import-type Reference from ReferenceWords
 */
final class DocsReferences
{
    /** @var array<string, true> */
    private readonly array $webFiles;

    /** @var list<string> */
    private readonly array $docsDirectories;

    /** 正規化して `/` を足した $docsPrefix */
    private readonly string $docsPrefix;

    /** @var array<string, string> 自分を指す参照の引用を確かめる本文(ファイル → 参照の範囲を消した中身) */
    private array $ownBodies = [];

    /**
     * @param  string  $webRoot  web の根(絶対パス)
     * @param  list<string>  $webFiles  web の追跡するファイル(根からの相対パス。解決に使う)
     * @param  string  $docsPrefix  docs の根から決める語の頭(docs の 設計/コード構成/検査.md 6 節。照合は $docs の木を読む)
     * @param  string  $historyDirectory  docs の根の直下の、指してはいけないディレクトリ
     * @param  list<string>  $ignoredWords  参照の語から外す語(完全一致。docs の参照でないファイル名)
     */
    public function __construct(
        private readonly string $webRoot,
        array $webFiles,
        private readonly DocsTree $docs,
        string $docsPrefix,
        private readonly string $historyDirectory,
        private readonly array $ignoredWords,
    ) {
        $this->webFiles = array_fill_keys($webFiles, true);
        $this->docsDirectories = $docs->topLevelDirectories();
        $this->docsPrefix = Paths::normalize($docsPrefix).'/';
    }

    /**
     * ファイル $targets(web の根からの相対パス)の中の違反(`種類 ファイル:行 語`。パスが決まったものは ` → パス` を足す)。テキストとして読めないファイル(NUL を含むか、
     * UTF-8 でないもの)は見ない。
     *
     * @param  list<string>  $targets
     * @return list<string>
     */
    public function violations(array $targets): array
    {
        $violations = [];

        foreach ($targets as $file) {
            $content = (string) file_get_contents($this->webRoot.'/'.$file);

            if (! str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8')) {
                $violations = [...$violations, ...$this->violationsIn($file, $content)];
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function violationsIn(string $file, string $content): array
    {
        $violations = [];
        $previous = null;
        $references = $this->references($content);

        foreach ($references as [$number, $reference]) {
            [$kind, $path] = $this->resolve($file, $reference['word'], $previous);

            if ($kind === 'docs' && $path !== null) {
                $kind = $this->checkDocs($path, $reference);
                $previous = $path;
            } elseif ($kind === 'web' && $path !== null) {
                $kind = $this->checkWeb($path, $reference, $file, $content, $references);
            }

            if ($kind !== 'web' && $kind !== 'docs') {
                $violations[] = self::describe($kind, $file.':'.$number, $reference['word'], $path);
            }
        }

        return $violations;
    }

    /**
     * 違反の 1 行(`種類 箇所 語`。パスが決まったものは ` → パス` を足す)。
     */
    private static function describe(string $kind, string $location, string $word, ?string $path): string
    {
        return $kind.' '.$location.' '.$word.($path === null ? '' : ' → '.$path);
    }

    /**
     * 中身 $content の参照の語と行番号(出てくる順。外す語を除く)。
     *
     * @return list<array{0: int, 1: Reference}>
     */
    private function references(string $content): array
    {
        $references = [];

        foreach (ReferenceWords::lines($content) as $index => $line) {
            foreach (ReferenceWords::inLine($line) as $reference) {
                if (! in_array($reference['word'], $this->ignoredWords, true)) {
                    $references[] = [$index + 1, $reference];
                }
            }
        }

        return $references;
    }

    /**
     * 語 $word の解決。戻り値 = [種類(web・docs・missing・unresolved・ambiguous), 解決したパス(web か docs の根から)]。
     *
     * @return array{0: string, 1: string|null}
     */
    private function resolve(string $file, string $word, ?string $previous): array
    {
        $target = Paths::normalize(str_ends_with($word, '/') ? $word.'README.md' : $word);
        $fromDocsRoot = $this->fromDocsRoot($target);

        if ($fromDocsRoot !== null) {
            return $fromDocsRoot;
        }

        $nearby = $previous === null ? null : $this->nearPrevious($previous, $target);

        if ($nearby !== null) {
            return ['docs', $nearby];
        }

        $web = $this->inWeb($file, $target);

        return $web !== null ? ['web', $web] : $this->bySuffix($target);
    }

    /**
     * docs の根から決まる語の解決(docs の 設計/コード構成/検査.md 6 節)。決まらなければ null。
     *
     * @return array{0: string, 1: string}|null
     */
    private function fromDocsRoot(string $target): ?array
    {
        $path = match (true) {
            str_starts_with($target, $this->docsPrefix) => substr($target, strlen($this->docsPrefix)),
            str_contains($target, '/') && in_array(explode('/', $target)[0], $this->docsDirectories, true) => $target,
            default => null,
        };

        if ($path === null) {
            return null;
        }

        // work/ の下は指すこと自体が違反なので、在るかによらず checkDocs に渡して history にする
        $known = $this->docs->has($path) || str_starts_with($path, $this->historyDirectory.'/');

        return [$known ? 'docs' : 'missing', $path];
    }

    private function inWeb(string $file, string $target): ?string
    {
        $directory = dirname($file);
        $candidates = [Paths::normalize(($directory === '.' ? '' : $directory.'/').$target), $target];

        foreach ($candidates as $candidate) {
            if (isset($this->webFiles[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 前の docs の参照 $previous のディレクトリから docs の根まで遡り、最初に見つかった $target。
     */
    private function nearPrevious(string $previous, string $target): ?string
    {
        $directory = dirname($previous);

        while (true) {
            $candidate = Paths::normalize(($directory === '.' ? '' : $directory.'/').$target);

            if ($this->docs->has($candidate)) {
                return $candidate;
            }

            if ($directory === '.') {
                return null;
            }

            $directory = dirname($directory);
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function bySuffix(string $target): array
    {
        $files = $this->docs->filesEndingWith($target, $this->historyDirectory);

        return match (count($files)) {
            0 => ['unresolved', null],
            1 => ['docs', $files[0]],
            default => ['ambiguous', null],
        };
    }

    /**
     * docs の参照 $path の確かめ。違反の種類か、違反が無ければ docs。
     *
     * @param  array{section: string|null, quote: string|null}  $reference
     */
    private function checkDocs(string $path, array $reference): string
    {
        return match (true) {
            str_starts_with($path, $this->historyDirectory.'/') => 'history',
            $reference['section'] !== null && ! $this->docs->hasSection($path, $reference['section']) => 'section',
            $reference['quote'] !== null && ! $this->docs->contains($path, $reference['quote']) => 'quote',
            default => 'docs',
        };
    }

    /**
     * web に決まった参照 $path の確かめ(docs の 設計/コード構成/検査.md 6 節)。違反の種類か、違反が無ければ web。
     *
     * @param  Reference  $reference
     * @param  list<array{0: int, 1: Reference}>  $references  $file の中身 $content の参照
     */
    private function checkWeb(string $path, array $reference, string $file, string $content, array $references): string
    {
        if ($reference['section'] === null && $reference['quote'] === null) {
            return 'web';
        }

        // 見出しは参照の範囲と重ならないので、節は元の中身で見る。引用だけを、自分を指すときは参照の範囲を消した本文で見る
        $text = $path === $file ? $content : TrackedFiles::contents($this->webRoot, $path);
        $quoted = $path === $file
            ? ($this->ownBodies[$file] ??= ReferenceWords::outside($content, $references))
            : $text;

        return match (true) {
            $reference['section'] !== null && ! DocsTree::bodyHasSection($text, $reference['section']) => 'section',
            $reference['quote'] !== null && ! str_contains($quoted, $reference['quote']) => 'quote',
            default => 'web',
        };
    }
}
