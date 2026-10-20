<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\AuthzResource;
use App\Queries\Authz\ResourceTypeCatalog;
use Illuminate\Validation\ValidationException;

/**
 * 認可のリソースを、対になるリソースグループと一緒に作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/リソース.md)。
 *
 * 止める規則: リソースグループの規則({@see CreateResourceGroup})に加えて、リソースタイプが列挙({@see ResourceTypeCatalog})にある。
 * URI は UTF-8 で 2,000 バイト以下(2.5。一意索引の行の上限に届かないように、圧縮前の長さで限る)。URI の長さ(1,000 文字)・リソースタイプと「:」で始まること・一意は DB が止める。
 */
class CreateResource
{
    use WritesCommonMaster;

    /** リソース URI の上限のバイト数(UTF-8。圧縮前) */
    public const MAX_URI_BYTES = 2000;

    public function __construct(
        private ResourceTypeCatalog $resourceTypes,
        private CreateResourceGroup $createResourceGroup,
    ) {}

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations  リソースグループの、ロケールのコード → 表示名・説明
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $code, ?int $parentId, string $resourceType, string $uri, array $translations, int $sortKey = 0): AuthzResource
    {
        $this->validate($resourceType, $uri);

        return $this->writeCommonMaster(function () use ($code, $parentId, $resourceType, $uri, $translations, $sortKey) {
            $group = $this->createResourceGroup->execute($code, $parentId, $translations, $sortKey);

            return AuthzResource::query()->create(['authz_resource_group_id' => $group->id, 'resource_type' => $resourceType, 'uri' => $uri]);
        });
    }

    /**
     * @throws ValidationException 規則に反するとき
     */
    private function validate(string $resourceType, string $uri): void
    {
        if (! $this->resourceTypes->hasResourceType($resourceType)) {
            throw ValidationException::withMessages(['resource_type' => ["無いリソースタイプです: {$resourceType}"]]);
        }

        if (strlen($uri) > self::MAX_URI_BYTES) {
            throw ValidationException::withMessages(['uri' => ['リソース URI は UTF-8 で '.self::MAX_URI_BYTES.' バイト以下にしてください']]);
        }
    }
}
