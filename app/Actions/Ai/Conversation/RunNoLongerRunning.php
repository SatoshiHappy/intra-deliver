<?php

namespace App\Actions\Ai\Conversation;

use RuntimeException;

/**
 * 確かめの時に実行がもう処理中でない(ほかの処理が実行を終えた)。中核の外へ抜けて、ジョブが何も書かずに終える(終えた処理が実行の終わりを
 * 書いたため。docs の 設計/AI/エージェントの実行/状態の移り方.md 9.11 の「終わった実行に処理中のステップの行が残る」を起こさない)。
 */
final class RunNoLongerRunning extends RuntimeException {}
