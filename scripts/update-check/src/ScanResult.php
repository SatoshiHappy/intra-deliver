<?php

namespace UpdateCheck;

/**
 * イメージ 1 つの検査結果(脆弱性と、Trivy が読んだ OS)。
 */
final readonly class ScanResult
{
    /**
     * @param  list<Vulnerability>  $vulnerabilities
     * @param  string  $osFamily  Trivy が読んだ OS(debian・alpine など)。読めなければ空
     * @param  string  $osName  OS の版(13.1・3.22.1 など)。読めなければ空
     */
    public function __construct(
        public string $image,
        public array $vulnerabilities,
        public string $osFamily,
        public string $osName,
    ) {}

    /**
     * 同じ脆弱性(脆弱性の ID とパッケージが同じ)があるか。
     */
    public function has(Vulnerability $other): bool
    {
        foreach ($this->vulnerabilities as $vulnerability) {
            if ($vulnerability->id === $other->id && $vulnerability->package === $other->package) {
                return true;
            }
        }

        return false;
    }
}
