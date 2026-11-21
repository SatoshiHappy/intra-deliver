<?php

namespace App\Queries\Ai;

/**
 * 会話の実行の鎖(前の実行を previous_run_id でさかのぼる)の再帰の SQL の 1 か所(鎖の定義を 1 つにするため)。
 */
final class RunChain
{
    /**
     * 鎖の CTE(名前 chain、列 = id・previous_run_id・started_at・depth。始めの実行の depth が 0)。束縛の値は始めの実行の id の 1 つ。
     *
     * @param  literal-string  $filter  始めの実行とさかのぼる実行の両方に掛ける条件(表の名前 ai_runs で書く。空なら掛けない)。
     *                                  合わない実行で、さかのぼりを止める
     * @return literal-string
     */
    public static function cte(string $filter = ''): string
    {
        $where = $filter === '' ? '' : " AND ({$filter})";

        return 'WITH RECURSIVE chain (id, previous_run_id, started_at, depth) AS ('
            .' SELECT id, previous_run_id, started_at, 0 FROM ai_runs WHERE id = ?'.$where
            .' UNION ALL SELECT ai_runs.id, ai_runs.previous_run_id, ai_runs.started_at, chain.depth + 1 FROM ai_runs'
            .' JOIN chain ON ai_runs.id = chain.previous_run_id WHERE TRUE'.$where.')';
    }
}
