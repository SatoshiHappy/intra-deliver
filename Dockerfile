# 公開用の app のイメージ(docs の 設計/公開の配備/構成.md 3.3.1)。build の文脈は配備専用の作業木(git が追跡するファイルだけ)。
# BuildKit 専用の構文(syntax の指示・RUN --mount・ヒアドキュメント・COPY --link)を使わない(legacy builder でも通す)。
# 段: base = 公式の FrankenPHP(Debian 系・PHP 8.4)に PHP の拡張を足した共通の土台 / build = 依存と資産を作る / app = 最終(非 root・Node 無し)。
# 土台・ビルド用のイメージは「タグ@digest」で固定する(frankenphp は提供元が出している最も細かい版 = PHP のパッチ版まで書く)。
# 版を上げるのは更新チェック(.github/workflows/update-check.yml)の報告を受けた取り込みの作業だけで、タグと digest を一緒に書き換える(digest だけの書き換えも土台の変更にあたる)。
# 同じイメージを書く場所(node の 2 行)は同じタグ@digest にそろえる。

# 土台は build の引数で差し替えられる(更新チェックが、新しい土台でも同じ脆弱性が残るかを調べるのに使う。既定 = 固定した値)。
# 書き換えるときはこの ARG の 1 行だけ(タグと digest を一緒に)。
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.1-php8.4.27-trixie@sha256:62f1ef7e0f7a43984d23d109eee58c5b1fb36232f49aaa675c17fee006ee883d
FROM ${FRANKENPHP_IMAGE} AS base

# 公式の手順(install-php-extensions)で拡張を足す。既知の問題のある拡張(imagick など)は入れない
RUN install-php-extensions pdo_pgsql pgsql intl opcache zip

WORKDIR /app


FROM base AS build

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/local/bin/composer
COPY --from=node:22.23.3-trixie-slim@sha256:154ba2f4d6fec323d28e4f4bb86bba4677f1223391a1979cf521304e03a98dfa /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22.23.3-trixie-slim@sha256:154ba2f4d6fec323d28e4f4bb86bba4677f1223391a1979cf521304e03a98dfa /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && node --version \
    && npm --version

# 依存の層をソースより前に置き、ソースの変更で依存を取り直さない。.npmrc(ignore-scripts=true)を依存の層に含める
# (無いと依存の install 系スクリプトが build で走り、供給網の守りが黙って外れる。build のログに true が出るのが守りの効いている印)。
# require-dev(Boost など)を入れない。--no-scripts なので package:discover は流れず、パッケージの一覧は起動で作られる
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY package.json package-lock.json .npmrc ./
RUN npm config get ignore-scripts && npm ci

COPY . .

# autoload はソースを写した後に最適化して作る(--no-dev = autoload-dev の tests/ を含めない。--no-scripts = package:discover を流さない。一覧は起動で作られる)
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && composer check-platform-reqs --no-dev

# npm run build の中で Wayfinder のプラグインが php artisan wayfinder:generate を流す(.env の無いまま)
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && npm run build


FROM base AS app

# 公式の本番の設定を土台にし、docker/app/php.ini で上書きする。非 root の利用者を作り、
# 非特権のポートで待ち受けるので frankenphp の capability を外す(公式の「capability なし」の手順。cap_drop: ALL で起動できるように)
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && groupadd --system --gid 10001 app \
    && useradd --system --uid 10001 --gid app --no-create-home --shell /usr/sbin/nologin app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R app:app /config/caddy /data/caddy

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/entrypoint /usr/local/bin/app-entrypoint

COPY . .
COPY --from=build /app/vendor ./vendor
COPY --from=build /app/public/build ./public/build

# コードは root の持ち物のまま(app の利用者は書けない)。書き込み先だけを app の利用者にする
# (storage/app は名前付きボリュームの載せる先。新しいボリュームには最初に載せたときこのディレクトリが写される。ほかは tmpfs で隠れる)
RUN mkdir -p storage/app storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R app:app storage bootstrap/cache

ENV SERVER_NAME=:8080

USER app

EXPOSE 8080

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
