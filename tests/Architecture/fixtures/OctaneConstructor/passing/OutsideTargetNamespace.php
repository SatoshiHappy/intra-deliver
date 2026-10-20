<?php

namespace Fixture\OctaneConstructor;

use Illuminate\Http\Request;

/**
 * 対象の名前空間(App)の外のクラスは規則の対象外。
 */
final class OutsideTargetNamespace
{
    public function __construct(
        private readonly Request $request,
    ) {}

    public function path(): string
    {
        return $this->request->path();
    }
}
