<?php

namespace UpdateCheck;

/**
 * 更新チェックが見る固定済みのイメージの一覧(設計/更新チェック.md 1 節の #4)。書く場所の正本は各ファイルで、ここは名前とファイルの対応だけ持つ。
 */
final class ImageCatalog
{
    /**
     * @return list<ImageSpec>
     */
    public static function all(): array
    {
        return [
            new ImageSpec('frankenphp', 'dunglas/frankenphp', Registry::DockerHub, 'dunglas/frankenphp', ['Dockerfile'], '/-php(\d+\.\d+)\./'),
            new ImageSpec('pgroonga', 'groonga/pgroonga', Registry::DockerHub, 'groonga/pgroonga', ['compose.yml', 'docker/db/Dockerfile'], '/-alpine-(\d+)$/D'),
            new ImageSpec('cloudflared', 'cloudflare/cloudflared', Registry::DockerHub, 'cloudflare/cloudflared', ['compose.publish.yml'], null),
            new ImageSpec('composer', 'composer', Registry::DockerHub, 'library/composer', ['Dockerfile'], null),
            new ImageSpec('node', 'node', Registry::DockerHub, 'library/node', ['Dockerfile'], '/^(\d+)\./'),
            new ImageSpec('trivy', 'ghcr.io/aquasecurity/trivy', Registry::Ghcr, 'aquasecurity/trivy', [TrivyImage::FILE], null),
        ];
    }
}
