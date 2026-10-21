<?php

namespace Tests\Support;

use Illuminate\Database\DatabaseManager;

/**
 * DB ファサードの transaction に渡った試行の回数を順に記録する DatabaseManager(接続は元の DatabaseManager のものを共有する)。
 * テストのトランザクションの中では業務処理のトランザクションが入れ子になり、Laravel がやり直さないので、やり直しの回数は渡した値で確かめる。
 */
final class TransactionAttemptsRecorder extends DatabaseManager
{
    /** @var list<int> */
    public array $attempts = [];

    public function __construct(DatabaseManager $manager)
    {
        parent::__construct($manager->app, $manager->factory);
        $this->connections = &$manager->connections;
    }

    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if ($method === 'transaction') {
            $attempts = $parameters[1] ?? $parameters['attempts'] ?? 1;
            $this->attempts[] = is_int($attempts) ? $attempts : -1;
        }

        return parent::__call($method, $parameters);
    }
}
