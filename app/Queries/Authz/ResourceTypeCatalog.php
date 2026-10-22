<?php

namespace App\Queries\Authz;

use App\Queries\Authz\Api\ResourceTypes;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 認可のリソースタイプとアクションの一覧の全体 = タグ {@see ResourceTypes::TAG} で束ねた各モジュールの一覧の和
 * (docs の 設計/メニュー/認可との結び付け.md 2.1)。和を作るのはここだけで、Authz の業務処理はこれで(リソースタイプ, アクション)を検査する。
 *
 * 同じリソースタイプの ID を 2 つの実装が返したら、和を作るときに例外にする(片方で上書きして検査を黙って弱めない)。
 */
class ResourceTypeCatalog
{
    /**
     * @param  iterable<ResourceTypes>  $resourceTypes
     */
    public function __construct(#[Tag(ResourceTypes::TAG)] private iterable $resourceTypes) {}

    /**
     * リソースタイプの ID → アクションの ID の一覧。
     *
     * @return array<string, list<string>>
     *
     * @throws LogicException 同じリソースタイプの ID を 2 つの実装が返したとき
     */
    public function actionsByResourceType(): array
    {
        $union = [];

        foreach ($this->resourceTypes as $definition) {
            foreach ($definition->actionsByResourceType() as $resourceType => $actions) {
                if (array_key_exists($resourceType, $union)) {
                    throw new LogicException("認可のリソースタイプ {$resourceType} を 2 つの一覧が定義している(".$definition::class.')');
                }

                $union[$resourceType] = $actions;
            }
        }

        return $union;
    }

    public function hasResourceType(string $resourceType): bool
    {
        return array_key_exists($resourceType, $this->actionsByResourceType());
    }

    /**
     * (リソースタイプ, アクション)の組が一覧にあるか。
     */
    public function hasAction(string $resourceType, string $action): bool
    {
        return in_array($action, $this->actionsByResourceType()[$resourceType] ?? [], true);
    }
}
