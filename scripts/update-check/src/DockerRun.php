<?php

namespace UpdateCheck;

use Throwable;

/**
 * `docker run --rm` を名前つきで流す。止めたときは docker のクライアントしか止まらず、コンテナはデーモンの側で動き続けるので、
 * 起こした後は(起動の成否・止めた理由・例外の型によらず)必ず名前でコンテナを止めて消す
 * (動き続けるコンテナがキャッシュのボリュームを握ったまま、次の検査を妨げない)。名前は乱数と連番で一意にする。
 * 起こす前に予算を確かめ、予算が切れていれば何も流さず(rm も流さず)予算切れを投げる。
 */
final class DockerRun
{
    private int $count = 0;

    private readonly string $prefix;

    public function __construct(
        private readonly CommandRunner $commands,
        private readonly string $root,
        private readonly TimeBudget $budget,
    ) {
        $this->prefix = 'updcheck-'.bin2hex(random_bytes(4));
    }

    /**
     * @param  list<string>  $arguments  `docker run --rm --name <名前>` の後に続く引数(オプション・イメージ・コマンド)
     *
     * @throws UnconfirmedException 予算切れ(起こす前)・起動できない・時間切れ(コンテナの後始末の後に投げ直す)
     */
    public function run(array $arguments, int $timeoutSeconds): CommandResult
    {
        $this->budget->limit($timeoutSeconds);
        $name = $this->prefix.'-'.(++$this->count);

        try {
            return $this->commands->run(['docker', 'run', '--rm', '--name', $name, ...$arguments], $this->root, $timeoutSeconds);
        } catch (Throwable $exception) {
            $failure = $this->remove($name);

            // 予算切れの型・想定外の例外は文を足せない(型で意味が決まる)ので、消せなかったことはログに残して元の例外を投げ直す
            if ($failure !== '' && ! $this->appendable($exception)) {
                error_log($failure);
            }

            throw $this->appendable($exception) ? new UnconfirmedException($exception->getMessage().$failure, 0, $exception) : $exception;
        }
    }

    /**
     * 理由の文に「消せなかった」を足してよい例外(予算切れ以外の想定した失敗)。
     */
    private function appendable(Throwable $exception): bool
    {
        return $exception instanceof UnconfirmedException && ! $exception instanceof BudgetExceededException;
    }

    /**
     * 名前のコンテナを消す。投げない(元の例外を置き換えない)。消せなかったときだけ理由の文を返す
     * (起こしていない回・既に消えている回の「そんなコンテナは無い」は理由にしない)。
     */
    private function remove(string $name): string
    {
        try {
            $result = $this->commands->run(['docker', 'rm', '--force', $name], $this->root, 30, true);
        } catch (Throwable $exception) {
            return "(コンテナ {$name} を消せなかった: ".$exception->getMessage().')';
        }

        if ($result->exitCode === 0 || stripos($result->stderr, 'No such container') !== false) {
            return '';
        }

        return "(コンテナ {$name} を消せなかった: ".$result->tail().')';
    }
}
