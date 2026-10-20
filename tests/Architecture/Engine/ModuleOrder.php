<?php

namespace Tests\Architecture\Engine;

use UnexpectedValueException;

/**
 * モジュールの依存の宣言(依存の向きに並べた一覧。モジュール名 → 依存してよいモジュール)の順序を照合する(汎用)。
 * 依存先に書けるのは、一覧で自分より前にあるモジュールだけ(自分自身・後ろのモジュール・宣言に無い名前は不可)。
 */
final class ModuleOrder
{
    /** 依存先が宣言に無い */
    public const UNDECLARED = 'modules.undeclared';

    /** 依存先が一覧で自分より前に無い(自分自身・後ろのモジュール) */
    public const NOT_EARLIER = 'modules.notEarlier';

    /**
     * 規則に合わない依存先を指摘にする(規則 ID = 種類、file = `依存元 → 依存先`)。
     *
     * @return list<Finding>
     *
     * @throws UnexpectedValueException 宣言の形が違うとき(モジュール名 → 文字列の一覧でない)
     */
    public static function findings(mixed $declaration): array
    {
        $modules = array_map('strval', array_keys(Values::table($declaration, 'モジュールの宣言')));
        $findings = [];

        foreach ($modules as $position => $module) {
            $earlier = array_slice($modules, 0, $position);

            foreach (Values::strings($declaration[$module], "モジュール {$module} の依存先") as $dependency) {
                if (! in_array($dependency, $modules, true)) {
                    $findings[] = new Finding(self::UNDECLARED, "{$module} → {$dependency}", null);
                } elseif (! in_array($dependency, $earlier, true)) {
                    $findings[] = new Finding(self::NOT_EARLIER, "{$module} → {$dependency}", null);
                }
            }
        }

        return $findings;
    }
}
