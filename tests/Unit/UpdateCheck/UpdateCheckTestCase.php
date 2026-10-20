<?php

namespace Tests\Unit\UpdateCheck;

use PHPUnit\Framework\TestCase;
use UpdateCheck\TimeBudget;

/**
 * 更新チェック(scripts/update-check)のテストの土台。DB は使わない。
 * スクリプトのクラスは composer の autoload の外にある(runner で composer install をせずに動かすため)ので、専用の読み込みを足す。
 */
abstract class UpdateCheckTestCase extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::loadScripts();
    }

    /**
     * データプロバイダは setUpBeforeClass より先に評価されるので、プロバイダからも呼べるようにする。
     */
    protected static function loadScripts(): void
    {
        require_once dirname(__DIR__, 3).'/scripts/update-check/autoload.php';
    }

    /**
     * このテストの間に error_log() に書かれた内容。PHPUnit が error_log() の出力を一時のファイルに受けて(書かれたままだとテストの出力になって risky)、
     * ini の error_log をそのファイルにしているので、それを読む。書かれることを期待するテストは expectErrorLog() を呼ぶ。
     */
    protected function errorLog(): string
    {
        return (string) file_get_contents((string) ini_get('error_log'));
    }

    protected static function budget(): TimeBudget
    {
        return TimeBudget::starting(600);
    }

    protected static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
