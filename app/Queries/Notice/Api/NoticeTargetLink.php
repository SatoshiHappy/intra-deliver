<?php

namespace App\Queries\Notice\Api;

/**
 * 見える対象の移り先({@see NoticeTargets::resolve()} の結果の値。docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。
 */
final readonly class NoticeTargetLink
{
    /**
     * @param  string  $url  対象の移り先の URL
     * @param  string|null  $label  対象の表示名(表示の時の今の名前。文の下の 1 行)。null = 出さない
     */
    public function __construct(
        public string $url,
        public ?string $label,
    ) {}
}
