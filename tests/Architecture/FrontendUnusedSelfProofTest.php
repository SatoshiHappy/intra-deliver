<?php

namespace Tests\Architecture;

use JsonException;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Process;
use Tests\Architecture\Engine\ProcessResult;
use UnexpectedValueException;

/**
 * 画面側の未使用のファイル・export(knip の設定 knip.json。K16)の自己証明。
 * 入口(pages/ の下)から辿れないファイル・使われない export・使われない型は、どれも失敗になる。
 * 違反の fixture は 1 種類ずつに分け、非 0 の終了がどの種類から来たかを見分けられるようにする。
 * どこからも辿れる fixture は通る。
 *
 * fixture の各ディレクトリ(tests/Architecture/fixtures/Knip/{violating,violating-export,violating-type,passing})を web/ に見立てて(package.json・tsconfig.json・resources/js を持つ)、
 * 通常と同じ設定のまま knip を走らせる。
 */
final class FrontendUnusedSelfProofTest extends SelfProofTestCase
{
    /** @var array<string, ProcessResult> fixture の種類ごとの結果(同じ入力に knip を何度も走らせない) */
    private static array $results = [];

    protected function findings(ProcessResult $result): array
    {
        try {
            $decoded = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("knip の出力を読めない: {$exception->getMessage()}\n".$result->describe(), previous: $exception);
        }

        if (! is_array($decoded) || ! isset($decoded['issues']) || ! is_array($decoded['issues'])) {
            throw new UnexpectedValueException("knip の出力に issues が無い\n".$result->describe());
        }

        $findings = [];

        foreach ($decoded['issues'] as $issue) {
            foreach (['files', 'exports', 'types'] as $kind) {
                foreach ($issue[$kind] ?? [] as $item) {
                    $findings[] = new Finding($kind, $issue['file'], $item['line'] ?? null);
                }
            }
        }

        return $findings;
    }

    public function test_detects_a_file_that_nothing_reaches(): void
    {
        $this->assertDetects('files', 'resources/js/lib/orphan.ts', null, self::knip('violating'));
    }

    public function test_detects_an_export_that_nothing_uses(): void
    {
        $this->assertDetects('exports', 'resources/js/lib/used.ts', 4, self::knip('violating-export'));
    }

    public function test_detects_a_type_that_nothing_uses(): void
    {
        $this->assertDetects('types', 'resources/js/lib/used.ts', 4, self::knip('violating-type'));
    }

    public function test_passes_files_and_exports_that_are_all_reached(): void
    {
        $this->assertClean(self::knip('passing'));
    }

    private static function knip(string $kind): ProcessResult
    {
        $root = dirname(__DIR__, 2);

        return self::$results[$kind] ??= Process::run(
            [$root.'/node_modules/.bin/knip', '--config', $root.'/knip.json', '--reporter', 'json'],
            $root.'/tests/Architecture/fixtures/Knip/'.$kind,
        );
    }
}
