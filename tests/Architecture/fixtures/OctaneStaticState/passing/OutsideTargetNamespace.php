<?php

namespace Fixture\OctaneStaticState;

/**
 * 対象の名前空間(App)の外のクラスは規則の対象外。
 */
final class OutsideTargetNamespace
{
    private static int $calls = 0;

    public function count(): int
    {
        return ++self::$calls;
    }
}
