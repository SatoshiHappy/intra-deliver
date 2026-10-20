<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * ファイルと関数の物理行の数を oxlint の `max-lines`・`max-lines-per-function` で数える(汎用)。
 *
 * oxlint は、それを固定の版で持つ包みのパッケージ(vite-plus など)が解決するのと同じ道筋で解決して起動し、
 * その版が包みの `dependencies` の固定と一致することを確かめる。この 2 規則だけの設定と JSON の出力で走らせ、
 * `message` が {@see self::FILE_MESSAGE}・{@see self::FUNCTION_MESSAGE} の形で読めなければ失敗する(版が変わって黙って数えなくならない)。
 */
final readonly class OxlintSizes
{
    public const FILE_UNIT = '(ファイル)';

    public const ANONYMOUS_UNIT = '(無名)';

    private const FILE_RULE = 'eslint(max-lines)';

    private const FUNCTION_RULE = 'eslint(max-lines-per-function)';

    private const FILE_MESSAGE = '/\AFile has too many lines \(([0-9]+)\)\.\z/';

    private const FUNCTION_MESSAGE = '/\AThe (?:async )?function (?:`([^`]+)` )?has too many lines \(([0-9]+)\)\.'
        .' Maximum allowed is ([0-9]+)\.\z/';

    /** oxlint の規則の区分(設定の categories。全部切って 2 規則だけにする) */
    private const CATEGORIES = ['correctness', 'suspicious', 'pedantic', 'perf', 'style', 'restriction', 'nursery'];

    private const RESOLVER = <<<'JS'
        const { createRequire } = await import('node:module');
        const { dirname, join } = await import('node:path');
        const { readFileSync } = await import('node:fs');
        const fromRoot = createRequire(join(process.cwd(), 'package.json'));
        const wrapperManifest = fromRoot.resolve(process.argv[1] + '/package.json');
        const wrapper = JSON.parse(readFileSync(wrapperManifest, 'utf8'));
        const oxlintRoot = dirname(dirname(createRequire(wrapperManifest).resolve('oxlint')));
        const oxlint = JSON.parse(readFileSync(join(oxlintRoot, 'package.json'), 'utf8'));
        process.stdout.write(JSON.stringify({
            bin: join(oxlintRoot, 'bin', 'oxlint'),
            version: oxlint.version,
            pinned: wrapper.dependencies?.oxlint ?? null,
        }));
        JS;

    /**
     * @param  string  $bin  oxlint の bin の絶対パス
     */
    private function __construct(private string $bin, private int $maxFileLines, private int $maxFunctionLines) {}

    /**
     * 根 $toolRoot の包みのパッケージ $wrapper が解決する oxlint で数える。
     *
     * @throws UnexpectedValueException 解決できないとき・版が固定と一致しないとき
     */
    public static function resolve(string $toolRoot, string $wrapper, int $maxFileLines, int $maxFunctionLines): self
    {
        $resolved = Values::table(
            JsonCommand::run(['node', '--input-type=module', '-e', self::RESOLVER, $wrapper], $toolRoot),
            "{$wrapper} が解決する oxlint",
        );
        $bin = $resolved['bin'] ?? null;

        if (! is_string($bin) || ! is_file($bin)) {
            throw new UnexpectedValueException("{$wrapper} が解決する oxlint の bin が無い: ".json_encode($bin));
        }

        self::assertPinned($resolved['version'] ?? null, $resolved['pinned'] ?? null, $wrapper);

        return new self($bin, $maxFileLines, $maxFunctionLines);
    }

    /**
     * 版 $version が固定 $pinned(`=<版>` か `<版>`)と一致すること。
     *
     * @throws UnexpectedValueException
     */
    public static function assertPinned(mixed $version, mixed $pinned, string $wrapper): void
    {
        if (! is_string($version) || ! is_string($pinned) || ! in_array($pinned, [$version, '='.$version], true)) {
            throw new UnexpectedValueException(
                "oxlint の版が {$wrapper} の固定と一致しない: 版 = ".json_encode($version).'・固定 = '.json_encode($pinned),
            );
        }
    }

    /**
     * 根 $root の下(パターン $ignorePatterns の外)の、上限を超えるファイルと関数(`<ファイル> <単位>` → 行の数の大きい順の並び)。
     *
     * @param  list<string>  $ignorePatterns
     * @return array<string, list<int>>
     *
     * @throws UnexpectedValueException oxlint が失敗したとき・出力が読めないとき
     */
    public function overLimit(string $root, array $ignorePatterns): array
    {
        $configuration = tempnam(sys_get_temp_dir(), 'oxlint-sizes-');

        if ($configuration === false) {
            throw new UnexpectedValueException('oxlint の設定の一時ファイルを作れない');
        }

        try {
            file_put_contents($configuration, json_encode($this->configuration(), JSON_THROW_ON_ERROR));
            $result = Process::run([
                'node', $this->bin, '-c', $configuration, '-f', 'json', '--disable-nested-config',
                ...array_map(fn (string $pattern): string => '--ignore-pattern='.$pattern, $ignorePatterns),
                '.',
            ], $root);
        } finally {
            unlink($configuration);
        }

        return $this->parse($result);
    }

    /**
     * oxlint の結果 $result の診断を数に読む。
     *
     * @return array<string, list<int>>
     *
     * @throws UnexpectedValueException
     */
    public function parse(ProcessResult $result): array
    {
        $decoded = in_array($result->exitCode, [0, 1], true) ? json_decode($result->stdout, true) : null;
        $diagnostics = is_array($decoded) ? ($decoded['diagnostics'] ?? null) : null;

        if (! is_array($diagnostics)) {
            throw new UnexpectedValueException("oxlint の出力が読めない\n".$result->describe());
        }

        $sizes = [];

        foreach ($diagnostics as $diagnostic) {
            [$key, $lines] = $this->entry(Values::table($diagnostic, 'oxlint の診断'));
            $sizes[$key][] = $lines;
        }

        ksort($sizes, SORT_STRING);

        return array_map(function (array $lines): array {
            rsort($lines, SORT_NUMERIC);

            return $lines;
        }, $sizes);
    }

    /**
     * 診断 1 つのキーと行の数。
     *
     * @param  array<mixed>  $diagnostic
     * @return array{0: string, 1: int}
     *
     * @throws UnexpectedValueException 規則・ファイル名・message の形が想定と違うとき
     */
    private function entry(array $diagnostic): array
    {
        $code = $diagnostic['code'] ?? null;
        $file = $diagnostic['filename'] ?? null;
        $message = $diagnostic['message'] ?? null;
        $label = '読めない oxlint の診断: '.json_encode($diagnostic, JSON_UNESCAPED_UNICODE);

        if (! is_string($file) || ! is_string($message)) {
            throw new UnexpectedValueException($label);
        }

        if ($code === self::FILE_RULE && preg_match(self::FILE_MESSAGE, $message, $match) === 1) {
            return [Paths::normalize($file).' '.self::FILE_UNIT, (int) $match[1]];
        }

        if ($code === self::FUNCTION_RULE && preg_match(self::FUNCTION_MESSAGE, $message, $match) === 1
            && (int) $match[3] === $this->maxFunctionLines) {
            $unit = $match[1] === '' ? self::ANONYMOUS_UNIT : $match[1];

            return [Paths::normalize($file).' '.$unit, (int) $match[2]];
        }

        throw new UnexpectedValueException($label);
    }

    /**
     * 2 規則だけの設定(物理行 = 空行とコメントを含む。即時に呼ぶ関数も関数に数える)。ほかの規則の区分は全部切る。
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        $physical = ['skipBlankLines' => false, 'skipComments' => false];

        return [
            'plugins' => [],
            'categories' => array_fill_keys(self::CATEGORIES, 'off'),
            'rules' => [
                'max-lines' => ['error', ['max' => $this->maxFileLines, ...$physical]],
                'max-lines-per-function' => [
                    'error',
                    ['max' => $this->maxFunctionLines, 'IIFEs' => true, ...$physical],
                ],
            ],
        ];
    }
}
