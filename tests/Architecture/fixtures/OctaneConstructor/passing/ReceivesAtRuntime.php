<?php

namespace App\Support\OctaneFixture;

use Closure;
use Illuminate\Http\Request;

/**
 * 要求はリゾルバのクロージャか実行時の引数で受ける(コンストラクタには置かない)。
 */
final class ReceivesAtRuntime
{
    /**
     * @param  Closure(): Request  $requestResolver
     */
    public function __construct(
        private readonly Closure $requestResolver,
        private readonly string $prefix,
    ) {}

    public function path(Request $request): string
    {
        return $this->prefix.$request->path();
    }

    public function currentPath(): string
    {
        return $this->prefix.($this->requestResolver)()->path();
    }
}
