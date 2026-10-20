<?php

namespace UpdateCheck;

/**
 * 脆弱性の検査に使う Trivy のイメージ(設計/更新チェック.md 2 節)。ghcr.io から取り、タグと digest を一緒に書いて固定する。
 * 2026-03 の供給網の事故(GHSA-69fq-xp46-6x23)の影響範囲の外で、公開から 2 週間以上たった版。上げるときはタグと digest を一緒に書き換える。
 */
final class TrivyImage
{
    /** このクラスを書いているファイル(#4 が固定の版を読む。アプリのルート起点) */
    public const FILE = 'scripts/update-check/src/TrivyImage.php';

    public const REFERENCE = 'ghcr.io/aquasecurity/trivy:0.76.0@sha256:2416d1f74045b57274df1a340eee6b510cacd73c66a6ccb8da092b117eb7d222';
}
