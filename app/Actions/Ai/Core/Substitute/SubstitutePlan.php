<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteInput;

/**
 * 組み終えた 1 回の送信(送る前の確かめに渡す字数と送る量の上限・送る前の知らせの見積もり・送った要求の原文の写しを持つ)。
 */
final readonly class SubstitutePlan
{
    /**
     * @param  array<string, mixed>  $record  送った要求の原文の写し(SDK は組んだ要求の本体を返さないので、中核が渡した物を写す)
     */
    public function __construct(
        public SendKind $kind,
        public SubstituteInput $input,
        public int $chars,
        public int $estimate,
        public int $sendLimit,
        public array $record,
    ) {}
}
