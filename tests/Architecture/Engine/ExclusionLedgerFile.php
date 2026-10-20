<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * 台帳⑧ のファイルの読み込み(汎用)。ExclusionLedger(台帳の行の照合)と分けたのは、読み込みを足すとクラスが物理行の上限を超えるため。
 */
final class ExclusionLedgerFile
{
    /**
     * web の根 $root の台帳(config/guardrails_allow.php の exclusions)を読む。
     *
     * @throws UnexpectedValueException
     */
    public static function read(string $root, string $packageCheck): ExclusionLedger
    {
        $allow = require $root.'/config/guardrails_allow.php';

        return ExclusionLedger::fromArray(is_array($allow) ? ($allow['exclusions'] ?? null) : null, $packageCheck);
    }
}
