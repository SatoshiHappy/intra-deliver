<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\DocsLocation;
use Tests\Architecture\Engine\DocsReferences as DocsReferencesCheck;
use Tests\Architecture\Engine\DocsTree;
use Tests\Architecture\Engine\ExclusionLedgerFile;
use Tests\Architecture\Engine\TrackedFiles;
use Tests\Architecture\Policy\DocsReferences;
use Tests\Architecture\Policy\Licenses;

/**
 * 参照の実在(docs の 設計/コード構成/検査/README.md 6.2)の照合と自己証明。判定は {@see DocsReferencesCheck}、固有の値は {@see DocsReferences}、
 * 対象は {@see TrackedFiles::targets()}、docs の場所は {@see DocsLocation}。
 */
final class DocsReferencesTest extends TestCase
{
    public function test_every_reference_in_web_resolves_to_one_existing_file_and_section(): void
    {
        $webRoot = dirname(__DIR__, 2);
        $docsRoot = DocsLocation::resolve(
            $webRoot,
            DocsReferences::DOCS_ROOT_VARIABLE,
            DocsReferences::MAIN_CHECKOUT_DOCS,
        );

        $violations = $this->violations($webRoot, $docsRoot);

        $this->assertSame(
            [],
            $violations,
            "docs の参照が 1 つのファイル・節に決まらない(docs = {$docsRoot}):\n".implode("\n", $violations),
        );
    }

    public function test_detects_unresolved_ambiguous_missing_section_quote_and_history_references(): void
    {
        // 期待は種類と箇所(拡張子を外したファイルと行)だけで比べる(参照の語をこのファイルに書くと、実物の木の照合がこのファイルの語を拾う)
        $this->assertSame([
            'quote README:5',
            'quote README:6',
            'quote README:7',
            'quote README:9',
            'quote README:10',
            'quote README:11',
            'section README:13',
            'quote README:14',
            'ambiguous app/Bad:3',
            'unresolved app/Bad:4',
            'missing app/Bad:5',
            'section app/Bad:6',
            'quote app/Bad:7',
            'history app/Bad:8',
            'unresolved app/Bad:9',
            'section app/Bad:10',
            'quote app/Bad:11',
            'quote app/Bad:12',
            'section app/Bad:13',
            'missing app/Bad:14',
            'section app/Bad:15',
            'history app/Bad:16',
            'history app/Bad:17',
            'history app/Bad:18',
            'section app/WebOnly:3',
            'quote app/WebOnly:4',
        ], array_map(
            fn (string $violation): string => (string) preg_replace(
                '/\.\w+(?=:\d+\z)/',
                '',
                implode(' ', array_slice(explode(' ', $violation), 0, 2)),
            ),
            $this->violationsInFixture('violating'),
        ));
    }

    public function test_passes_web_files_excluded_words_heading_forms_and_relative_references(): void
    {
        $this->assertSame([], $this->violationsInFixture('passing'));
    }

    /**
     * @return list<string>
     */
    private function violationsInFixture(string $kind): array
    {
        $root = __DIR__.'/fixtures/DocsReferences/'.$kind;

        return $this->violations($root.'/web', $root.'/docs');
    }

    /**
     * web の根 $webRoot の照合の違反。対象 = {@see TrackedFiles::targets()}(台帳⑧ R1)、
     * 参照の語から外す語 = 台帳⑧ R1 の pattern の値。
     *
     * @return list<string>
     */
    private function violations(string $webRoot, string $docsRoot): array
    {
        $files = TrackedFiles::notIgnored($webRoot);
        $ledger = ExclusionLedgerFile::read($webRoot, Licenses::LEDGER_CHECK);
        $targets = TrackedFiles::targets($webRoot, $ledger, DocsReferences::EXCLUSION_CHECK, $files);
        $ignoredWords = $ledger->patterns([DocsReferences::EXCLUSION_CHECK]);
        $docs = new DocsTree($docsRoot);

        $check = new DocsReferencesCheck(
            $webRoot,
            $files,
            $docs,
            DocsReferences::MAIN_CHECKOUT_DOCS,
            DocsReferences::HISTORY_DIRECTORY,
            $ignoredWords,
        );

        return $check->violations($targets);
    }
}
