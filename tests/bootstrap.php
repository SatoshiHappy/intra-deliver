<?php

/*
 * PHPUnit の起動(phpunit.xml の bootstrap)。作業木ごとのテスト DB の名前を DB_DATABASE に置く
 * (Tests\Support\WorktreeTestEnvironment。docs の 設計/コード構成/検査.md 6 節)。
 * Laravel の .env の読み込みは既にある環境変数を上書きしないので、読み込みより前に putenv・$_ENV・$_SERVER へ置く。
 */

require __DIR__.'/../vendor/autoload.php';

use Tests\Support\WorktreeTestEnvironment;

$testDatabase = WorktreeTestEnvironment::testDatabaseName(dirname(__DIR__));

putenv("DB_DATABASE={$testDatabase}");
$_ENV['DB_DATABASE'] = $testDatabase;
$_SERVER['DB_DATABASE'] = $testDatabase;
