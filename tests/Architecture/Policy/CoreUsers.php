<?php

namespace Tests\Architecture\Policy;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * 機械検査 (f) のうち、会話の中核を参照してよい側(docs の 設計/AI/検査で守る結果.md の (f)、表の正本 = 設計/AI/会話の中核.md 13.1)。
 * 中核の依れる物の並び({@see CoreDependencies})は中核から外への向きを縛り、ここは外から中核への向きを縛る(繋ぎが具体的な版に依らず、
 * ① と ② が ③ の口だけを使うため)。
 */
final class CoreUsers
{
    /**
     * 版を参照してよい、版の外の物(版を選ぶ 1 か所。名前は ③ の繋ぎの実装が決める。足すときは理由を書く)。
     *
     * @var list<array{class: non-empty-string, reason: string}>
     */
    public const VERSION_PICKERS = [
        [
            'class' => 'App\\Actions\\Ai\\Conversation\\CoreVersion',
            'reason' => '既定の版(代わりの版)を結ぶ。手元だけのリポジトリのプロバイダが後から移した版で結び直す(指示の組み立ての口と同じ形)',
        ],
    ];

    #[TestRule]
    public function coreContractUsers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(...Modules::selectors('Ai'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(CoreDependencies::CONTRACT))
            ->because('機械検査 (f): 中核を参照してよいのは Ai モジュールだけ(① と ② は ③ の口を使う)')
            ->nonIgnorable();
    }

    #[TestRule]
    public function coreVersionUsers(): Rule
    {
        $versions = array_map(Selector::inNamespace(...), CoreDependencies::VERSIONS);

        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->excluding(...$versions, ...self::pickers())
            ->shouldNot()->dependOn()
            ->classes(...$versions)
            ->because('機械検査 (f): 版を参照してよいのは版自身と、版を選ぶ 1 か所(CoreUsers::VERSION_PICKERS)だけ')
            ->nonIgnorable();
    }

    /**
     * @return list<SelectorInterface>
     */
    private static function pickers(): array
    {
        return array_map(
            static fn (array $entry): SelectorInterface => Selector::classname($entry['class']),
            self::VERSION_PICKERS,
        );
    }
}
