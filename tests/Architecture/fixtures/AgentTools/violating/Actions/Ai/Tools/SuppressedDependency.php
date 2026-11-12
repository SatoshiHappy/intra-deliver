<?php

namespace App\Actions\Ai\Tools;

use App\Queries\Upload\Api\UploadResponses;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)
 */
final class SuppressedDependency
{
    // @phpstan-ignore phpat.agentToolDependencies
    public function responses(UploadResponses $responses): UploadResponses
    {
        return $responses;
    }
}
