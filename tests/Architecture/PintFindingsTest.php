<?php

namespace Tests\Architecture;

use JsonException;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Finding;
use Tests\Architecture\Engine\PintFindings;
use UnexpectedValueException;

final class PintFindingsTest extends TestCase
{
    public function test_maps_the_agent_format(): void
    {
        $json = '{"tool": "pint", "result": "fail", "files": [{"path": "a/B.php", "fixers": ["no_unused_imports", "single_quote"]}]}';

        $this->assertEquals([
            new Finding('no_unused_imports', 'a/B.php', null),
            new Finding('single_quote', 'a/B.php', null),
        ], PintFindings::fromJson($json));
    }

    public function test_maps_the_php_cs_fixer_json_format(): void
    {
        $json = '{"about": "PHP CS Fixer", "files": [{"name": "a/B.php", "appliedFixers": ["no_unused_imports"], "diff": "-"}], "time": {"total": 0}, "memory": 0}';

        $this->assertEquals([new Finding('no_unused_imports', 'a/B.php', null)], PintFindings::fromJson($json));
    }

    public function test_returns_no_findings_for_a_passed_result_in_either_format(): void
    {
        $this->assertSame([], PintFindings::fromJson('{"tool": "pint", "result": "passed"}'));
        $this->assertSame([], PintFindings::fromJson('{"about": "PHP CS Fixer", "files": [], "time": {"total": 0}, "memory": 0}'));
    }

    public function test_rejects_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        PintFindings::fromJson('Pint crashed');
    }

    public function test_rejects_a_failed_result_without_files(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PintFindings::fromJson('{"tool": "pint", "result": "fail"}');
    }

    public function test_rejects_a_file_without_fixer_names(): void
    {
        $this->expectException(UnexpectedValueException::class);

        PintFindings::fromJson('{"files": [{"name": "a/B.php"}]}');
    }
}
