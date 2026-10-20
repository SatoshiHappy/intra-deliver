<?php

namespace Tests\Architecture;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Architecture\Engine\Directories;

final class DirectoriesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/directories-test-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/b', 0777, true);
        mkdir($this->root.'/a');
        mkdir($this->root.'/.hidden');
        touch($this->root.'/c.php');
    }

    protected function tearDown(): void
    {
        foreach (['b', 'a', '.hidden'] as $directory) {
            rmdir($this->root.'/'.$directory);
        }

        unlink($this->root.'/c.php');
        rmdir($this->root);

        parent::tearDown();
    }

    public function test_names_lists_only_directories_in_name_order(): void
    {
        $this->assertSame(['.hidden', 'a', 'b'], Directories::names($this->root));
    }

    public function test_names_rejects_a_path_that_is_not_a_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Directories::names($this->root.'/c.php');
    }
}
