<?php

namespace App\Queries\Alpha\Api;

/**
 * 公開の口の読み取りの interface は識別子と配列を返す
 */
interface ReadsValues
{
    /**
     * @param  list<int>  $recordIds
     * @return array<int, string>
     */
    public function namesOf(array $recordIds): array;
}
