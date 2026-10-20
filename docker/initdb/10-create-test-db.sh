#!/bin/sh
# 初回の initdb のときだけ実行される。コンテナを初めて作ったときの .env の DB_DATABASE + _test を作る。
# ほかの作業木の分は手で作る(docs の 設計/コード構成/検査.md 6 節)。
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE \"${POSTGRES_DB}_test\";"
