<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Actions\Ai\Core\SendFailure;

/**
 * 代わりの版の 1 回の試しの失敗(区分と、送った後の知らせに載せる誤りの状態と流れの途中か。長すぎるなら実際の量と上限 =
 * docs の 設計/AI/会話の中核の口と受け手.md 13.3 の失敗の種類)。
 */
final readonly class SubstituteFailure
{
    public function __construct(
        public SendFailure $failure,
        public ?int $status,
        public bool $midStream,
        public ?int $actualTokens = null,
        public ?int $limitTokens = null,
    ) {}
}
