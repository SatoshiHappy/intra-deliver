<?php

namespace Tests\Architecture;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\Findings;
use UnexpectedValueException;

final class FindingsTest extends TestCase
{
    public function test_from_json_maps_a_top_level_list(): void
    {
        $findings = Findings::fromJson('[{"RuleID": "generic-api-key", "File": "a.env", "StartLine": 3}]', 'RuleID', 'File', 'StartLine');

        $this->assertEquals([new Finding('generic-api-key', 'a.env', 3)], $findings);
    }

    public function test_from_json_reads_the_list_under_a_key_and_allows_no_line(): void
    {
        $findings = Findings::fromJson('{"items": [{"rule": "r", "file": "f.php"}]}', 'rule', 'file', null, 'items');

        $this->assertEquals([new Finding('r', 'f.php', null)], $findings);
    }

    public function test_from_json_rejects_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        Findings::fromJson('not json', 'rule', 'file');
    }

    public function test_from_json_rejects_an_item_without_the_rule_key(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Findings::fromJson('[{"file": "f.php"}]', 'rule', 'file');
    }

    public function test_from_json_rejects_a_non_integer_line(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Findings::fromJson('[{"rule": "r", "file": "f.php", "line": "3"}]', 'rule', 'file', 'line');
    }

    public function test_from_json_rejects_an_object_where_a_list_is_expected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Findings::fromJson('{"rule": "r", "file": "f.php"}', 'rule', 'file');
    }

    public function test_from_lines_keeps_only_matching_lines(): void
    {
        $text = "Report\nsrc/A.php:12:no-static\n\nsrc/B.php:3:no-container\nTotal: 2\n";

        $findings = Findings::fromLines($text, '/^(?<file>[^:]+):(?<line>\d+):(?<rule>\S+)$/D');

        $this->assertEquals([
            new Finding('no-static', 'src/A.php', 12),
            new Finding('no-container', 'src/B.php', 3),
        ], $findings);
    }

    public function test_from_lines_rejects_a_pattern_without_the_named_groups(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Findings::fromLines('src/A.php:12', '/^(?<file>[^:]+):(\d+)$/D');
    }

    public function test_finding_matches_by_rule_file_suffix_and_line(): void
    {
        $finding = new Finding('rule-a', '/abs/tests/Architecture/fixtures/x/violating/Foo.php', 12);

        $this->assertTrue($finding->matches('rule-a', 'violating/Foo.php', 12));
        $this->assertTrue($finding->matches('rule-a', 'violating/Foo.php', null));
        $this->assertFalse($finding->matches('rule-b', 'violating/Foo.php', 12));
        $this->assertFalse($finding->matches('rule-a', 'violating/Foo.php', 13));
        $this->assertFalse($finding->matches('rule-a', 'Foo.php.bak', 12));
        $this->assertFalse($finding->matches('rule-a', 'olating/Foo.php', 12));
    }
}
