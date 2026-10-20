<?php

namespace App\Actions\Alpha\Api;

/**
 * 公開の口の値のクラス(昇格した public プロパティと、昇格でない public プロパティは識別子)
 */
final readonly class AlphaValue
{
    public int $recordId;

    public function __construct(public int $id, public ?string $name = null)
    {
        $this->recordId = $id;
    }
}
