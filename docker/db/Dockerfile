# 公開用の db のイメージ(docs の 設計/公開の配備/構成.md 3.3.2)。開発用と同じ土台に、初期化のスクリプトを焼く(ホストのディレクトリを載せない)。
# build の文脈は web の docker/(compose.publish.yml の db の build.context)。BuildKit 専用の構文を使わない(legacy builder でも通す)。
# 土台か初期化のスクリプトを変えたら、compose.publish.yml の db のイメージのタグを上げる(版 IMAGE_TAG には連動させない)。
# スクリプトの実行権限は git が持つファイルのモード(100755)をそのまま写す。

# 土台は build の引数で差し替えられる(更新チェックが新しい土台を検査するのに使う。既定 = 固定した値。compose.yml の db と同じタグ@digest にそろえる)
ARG PGROONGA_IMAGE=groonga/pgroonga:4.1.0-alpine-18@sha256:fa244ecc61ce0251233823373cebe4d126f58d4bb9f5edca67b30b97161a5c79
FROM ${PGROONGA_IMAGE}

COPY initdb-publish/ /docker-entrypoint-initdb.d/
