#!/bin/sh
# 公開用の db の初期化(docs の 設計/公開の配備/DBと秘密.md 4.1・4.2)。公式のエントリポイントが、データディレクトリが空のときだけ
# 一度流す(接続はソケットだけの一時のサーバー、ロールは POSTGRES_USER = スーパーユーザー)。開発用の docker/initdb とは別(テスト用の DB は作らない)。
# db.env の APP_DB_NAME・APP_DB_USER・APP_DB_PASSWORD から、アプリ用ロール(DB の所有者・非スーパーユーザー)と、その所有の DB を作る。
# - 名前とパスワードはコマンドの引数と SQL の文字に書かない。psql が \getenv で環境変数から読み、:"…"(識別子)・:'…'(リテラル)で引用する。
# - パスワードを含む文が失敗してもログに出さない。サーバー側 = このセッションの log_min_error_statement を panic にする
#   (既定 error では失敗した文の全文がサーバーのログに出る)。psql 側 = VERBOSITY を terse にする(エラーに問い合わせの文の抜粋を付けない)。
# - statement_timeout・lock_timeout は値が決まるまで書かない(既定 0 = 無効のまま。DBと秘密.md 4.1)。
# - 1 つでも失敗したら止まる(set -e と ON_ERROR_STOP。公式のエントリポイントも初期化を止める)。
set -eu

: "${APP_DB_NAME:?APP_DB_NAME を db.env に設定してください}"
: "${APP_DB_USER:?APP_DB_USER を db.env に設定してください}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD を db.env に設定してください}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --no-password --no-psqlrc --dbname postgres <<'SQL'
\set VERBOSITY terse
SET log_min_error_statement = panic;
\getenv app_db_name APP_DB_NAME
\getenv app_db_user APP_DB_USER
\getenv app_db_password APP_DB_PASSWORD
CREATE ROLE :"app_db_user" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT CONNECTION LIMIT 20 PASSWORD :'app_db_password';
CREATE DATABASE :"app_db_name" OWNER :"app_db_user";
SQL

# 終わりの印(秘密を含まない)。初期化の確かめは、ログをこの行に絞って読む(DBと秘密.md 4.3.1)
echo 'initdb-publish: アプリ用ロールと、その所有の DB を作成しました'
