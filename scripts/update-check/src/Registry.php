<?php

namespace UpdateCheck;

/**
 * コンテナのレジストリ(Distribution API の匿名のトークンで読む)。
 */
enum Registry: string
{
    case DockerHub = 'docker.io';
    case Ghcr = 'ghcr.io';

    public function api(): string
    {
        return match ($this) {
            self::DockerHub => 'https://registry-1.docker.io',
            self::Ghcr => 'https://ghcr.io',
        };
    }

    public function tokenUrl(string $path): string
    {
        return match ($this) {
            self::DockerHub => 'https://auth.docker.io/token?service=registry.docker.io&scope=repository:'.$path.':pull',
            self::Ghcr => 'https://ghcr.io/token?scope=repository:'.$path.':pull',
        };
    }
}
