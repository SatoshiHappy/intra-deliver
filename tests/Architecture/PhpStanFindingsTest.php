<?php

namespace Tests\Architecture;

use JsonException;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\PhpStanFindings;
use UnexpectedValueException;

final class PhpStanFindingsTest extends TestCase
{
    public function test_maps_file_messages_and_general_errors(): void
    {
        $json = '{"totals": {"errors": 1, "file_errors": 2}, "files": {"/abs/A.php": {"errors": 2, "messages": ['
            .'{"message": "m1", "line": 12, "ignorable": false, "identifier": "rule.a"},'
            .'{"message": "m2", "line": null, "ignorable": true}'
            .']}}, "errors": ["config is broken"]}';

        $this->assertEquals([
            new Finding('rule.a', '/abs/A.php', 12),
            new Finding(PhpStanFindings::MISSING_IDENTIFIER, '/abs/A.php', null),
            new Finding(PhpStanFindings::GENERAL_ERROR, '(全体)', null),
        ], PhpStanFindings::fromJson($json));
    }

    public function test_returns_no_findings_for_a_clean_result(): void
    {
        $this->assertSame([], PhpStanFindings::fromJson('{"totals": {"errors": 0, "file_errors": 0}, "files": [], "errors": []}'));
    }

    public function test_rejects_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        PhpStanFindings::fromJson('Fatal error: out of memory');
    }

    public function test_rejects_output_without_files_and_errors(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PhpStanFindings::fromJson('{"result": "passed", "errors": 0}');
    }

    public function test_rejects_a_message_with_a_non_integer_line(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PhpStanFindings::fromJson('{"files": {"/abs/A.php": {"messages": [{"message": "m", "line": "12", "identifier": "rule.a"}]}}, "errors": []}');
    }
}
