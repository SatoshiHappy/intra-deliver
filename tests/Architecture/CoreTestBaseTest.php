<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Unit\Actions\Ai\Core\EmptyContainerTestCase;

/**
 * 機械検査 (f) のうち、中核のテストの基底(docs の 設計/AI/検査で守る結果.md の (f))。中核のテスト(約束と両方の版)は、空のコンテナの
 * 基底 {@see EmptyContainerTestCase} を(間の基底を通してでも)継ぐ。基底の中で型で止まらない形が落ちることは、基底の自己証明が見る。
 */
final class CoreTestBaseTest extends TestCase
{
    /** 中核のテストの置き場(公開の側と、手元のリポジトリの側。無い置き場は見ない) */
    private const DIRECTORIES = ['tests/Unit/Actions/Ai/Core', 'ai-private/tests/Unit/Actions/Ai/Core'];

    public function test_core_tests_use_the_empty_container_base(): void
    {
        $root = dirname(__DIR__, 2);
        $classes = [];

        foreach (self::DIRECTORIES as $directory) {
            $classes = [...$classes, ...self::classesIn($root.'/'.$directory)];
        }

        $this->assertNotSame([], $classes);
        $this->assertSame([], self::outsideTheBase($classes));
    }

    public function test_detects_a_core_test_outside_the_base(): void
    {
        $classes = self::classesIn(__DIR__.'/fixtures/CoreTestBase');

        $this->assertSame(
            [
                'Tests\Architecture\Fixtures\CoreTestBase\AppBootingCoreFixture',
                'Tests\Architecture\Fixtures\CoreTestBase\PlainCoreFixture',
            ],
            self::outsideTheBase($classes),
        );
    }

    /**
     * テストのクラスのうち、基底を(間の基底を通してでも)継がない物(基底そのものは除く)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    private static function outsideTheBase(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => is_subclass_of($class, TestCase::class)
                && $class !== EmptyContainerTestCase::class
                && ! is_subclass_of($class, EmptyContainerTestCase::class),
        ));
    }

    /**
     * 置き場の下のファイルが宣言するクラス(ファイルを読み込んで名前を引く)。
     *
     * @return list<class-string>
     */
    private static function classesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $classes = [...$classes, ...self::declaredBy($file->getPathname())];
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * ファイルが宣言するクラスの名前(名前空間と class の宣言から組む)。
     *
     * @return list<class-string>
     */
    private static function declaredBy(string $path): array
    {
        $source = (string) file_get_contents($path);
        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
        preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $names);
        require_once $path;

        return array_values(array_filter(
            array_map(static fn (string $name): string => ($namespace[1] ?? '').'\\'.$name, $names[1]),
            class_exists(...),
        ));
    }
}
