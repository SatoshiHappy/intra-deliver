<?php

namespace Tests\Unit\UpdateCheck;

use UpdateCheck\NpmWorkspace;

/**
 * npm ci 用の一時のディレクトリ。消す処理は元の結果・例外を置き換えない(投げない)。失敗は error_log() に残りのパスを出す。
 */
final class NpmWorkspaceTest extends UpdateCheckTestCase
{
    public function test_作って中身ごと消せる(): void
    {
        $workspace = NpmWorkspace::create(self::root(), sys_get_temp_dir());
        mkdir($workspace->path.'/node_modules/a', 0777, true);
        file_put_contents($workspace->path.'/node_modules/a/x.js', 'x');

        $this->assertFileExists($workspace->path.'/package.json');

        $workspace->remove();

        $this->assertDirectoryDoesNotExist($workspace->path);
    }

    public function test_消す前にディレクトリが無くても投げずに理由を残す(): void
    {
        $this->expectErrorLog();
        $workspace = NpmWorkspace::create(self::root(), sys_get_temp_dir());
        $workspace->remove();

        $workspace->remove();

        $this->assertStringContainsString($workspace->path, $this->errorLog());
    }

    public function test_消せないものがあっても投げず_残ったパスを出す(): void
    {
        if (posix_geteuid() === 0) {
            // root は書き込み権限の無いディレクトリの中も消せるので、この確かめはできない(root では実行されない)
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectErrorLog();
        $workspace = NpmWorkspace::create(self::root(), sys_get_temp_dir());
        mkdir($workspace->path.'/locked');
        file_put_contents($workspace->path.'/locked/file', 'x');
        file_put_contents($workspace->path.'/other.txt', 'x');
        chmod($workspace->path.'/locked', 0500);

        try {
            $workspace->remove();
            $this->assertStringContainsString($workspace->path.'/locked/file', $this->errorLog());
            // 1 項目の失敗で止まらず、消せるものは消す
            $this->assertFileDoesNotExist($workspace->path.'/other.txt');
            $this->assertFileDoesNotExist($workspace->path.'/package.json');
        } finally {
            chmod($workspace->path.'/locked', 0700);
            $workspace->remove();
        }

        $this->assertDirectoryDoesNotExist($workspace->path);
    }

    public function test_読めないディレクトリがあっても残りを消し_全部のパスを出す(): void
    {
        if (posix_geteuid() === 0) {
            // root は権限に関わらず読めるので、この確かめはできない(root では実行されない)
            $this->addToAssertionCount(1);

            return;
        }

        $this->expectErrorLog();
        $workspace = NpmWorkspace::create(self::root(), sys_get_temp_dir());
        mkdir($workspace->path.'/unreadable');
        file_put_contents($workspace->path.'/unreadable/file', 'x');
        mkdir($workspace->path.'/readable');
        file_put_contents($workspace->path.'/readable/file', 'x');
        chmod($workspace->path.'/unreadable', 0000);

        try {
            $workspace->remove();
            $this->assertStringContainsString($workspace->path.'/unreadable', $this->errorLog());
            $this->assertDirectoryDoesNotExist($workspace->path.'/readable');
        } finally {
            chmod($workspace->path.'/unreadable', 0700);
            $workspace->remove();
        }

        $this->assertDirectoryDoesNotExist($workspace->path);
    }
}
