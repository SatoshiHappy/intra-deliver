<?php

namespace UpdateCheck;

/**
 * PHP のストリームで HTTP の要求を出す(拡張を足さない。リダイレクトは追う)。
 * 要求全体の締め切り(= min(要求の上限、時間の予算の残り))を fopen の前に決め、本文はループで読み、読むたびにその締め切りを確かめて超えたら打ち切る。
 * 見出しとリダイレクトの段は PHP の中なので、読み取り 1 回の上限(= 締め切りまでの残り)で抑える(少しずつ見出しを返す相手には全体の締め切りが効かない。残課題)。
 * 締め切りを超えたとき(fopen の失敗が締め切りの時刻を過ぎていたときを含む)、締め切りが予算で縮められていれば予算切れ、そうでなければ時間切れ。
 * 予算が切れていれば要求を始めない。ページ送りは要求ごとにこの締め切りを持つ。
 */
final readonly class StreamHttpClient implements HttpClient
{
    /** 時刻の粒度による取りこぼしの許容(fopen の時間切れが締め切りより僅かに早く返っても、時間切れとして扱う) */
    private const TOLERANCE = 0.5;

    public function __construct(
        private TimeBudget $budget,
        private int $requestSeconds = 30,
    ) {}

    public function request(string $method, string $url, array $headers = []): HttpResponse
    {
        $limit = $this->budget->limit($this->requestSeconds);
        $deadline = microtime(true) + $limit;
        $budgetBound = $limit < $this->requestSeconds;
        $lines = [];

        foreach ($headers + ['User-Agent' => 'intra-deliver-update-check'] as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'ignore_errors' => true,
            'timeout' => $limit,
        ]]);

        // fopen は HTTP のエラーの応答(ignore_errors)でもストリームを返す。応答の見出しはストリームのメタデータにある
        $stream = @fopen($url, 'r', false, $context);

        if ($stream === false) {
            throw $this->openFailure($method, $url, $deadline, $budgetBound);
        }

        $meta = stream_get_meta_data($stream);

        try {
            $body = $this->readBody($stream, $deadline, $budgetBound);
        } finally {
            fclose($stream);
        }

        $wrapperData = $meta['wrapper_data'] ?? [];

        return $this->parse(is_array($wrapperData) ? array_values(array_filter($wrapperData, is_string(...))) : [], $body);
    }

    /**
     * fopen の失敗。時刻が締め切りを過ぎていれば時間切れ(縮めた上限なら予算切れ)、締め切りの前の失敗(名前解決・接続の拒否など)は通信できない。
     */
    private function openFailure(string $method, string $url, float $deadline, bool $budgetBound): UnconfirmedException
    {
        if (microtime(true) < $deadline - self::TOLERANCE) {
            return new UnconfirmedException("通信できない: {$method} ".$this->host($url));
        }

        return $this->timeout($budgetBound);
    }

    private function timeout(bool $budgetBound): UnconfirmedException
    {
        return $budgetBound ? new BudgetExceededException : new UnconfirmedException('HTTP の応答が時間切れ');
    }

    /**
     * 本文を読む。読み取りのたびに締め切りを確かめ、超えたら打ち切る(予算で縮めた締め切りなら予算切れ)。
     *
     * @param  resource  $stream
     *
     * @throws UnconfirmedException
     */
    private function readBody($stream, float $deadline, bool $budgetBound): string
    {
        $body = '';
        stream_set_timeout($stream, 1);

        while (! feof($stream)) {
            if (microtime(true) > $deadline) {
                throw $this->timeout($budgetBound);
            }

            $body .= (string) fread($stream, 8192);
        }

        return $body;
    }

    /**
     * リダイレクトを追うと見出しの塊が続く。最後の塊を応答とする。
     *
     * @param  list<string>  $lines
     */
    private function parse(array $lines, string $body): HttpResponse
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $status = (int) (explode(' ', $line)[1] ?? 0);
                $headers = [];

                continue;
            }

            $pair = explode(':', $line, 2);

            if (count($pair) === 2) {
                $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return new HttpResponse($status, $headers, $body);
    }

    private function host(string $url): string
    {
        return (string) parse_url($url, PHP_URL_HOST);
    }
}
