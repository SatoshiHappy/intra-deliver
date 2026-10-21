#!/usr/bin/env node
// 機械検査の記録(scripts/ci/record が書く 1 行 1 JSON)を道具ごとに集計し、Markdown の表で出す。
// 使い方(web/ で実行): node scripts/quality/report.mjs [--since YYYY-MM-DD]
// 正本 = docs の 設計/コード構成/検査/結果の記録.md の 6.6「結果の記録」(件の数え方・区分・率の分母・集計の使い方)。
// scripts/ci の外に置く(scripts/ci は記録の cfg の対象なので、中に置くと集計を直しただけで開いている件が無効に数えられる)。
import { readFileSync, realpathSync } from 'node:fs';
import { join } from 'node:path';
import { argv, env, exit, stderr, stdout } from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

// 一括検査の行は回数と秒だけに使う(段の中の道具と二重に数えない)
const BATCH_TOOLS = new Set(['ci-check', 'ci-full']);
const KINDS = ['有効', '無効', '揺れ', '判定不能', '未解消'];

export function logPath(environment) {
    if (environment.QUALITY_LOG) {
        return environment.QUALITY_LOG;
    }
    const state =
        environment.XDG_STATE_HOME || `${environment.HOME || ''}/.local/state`;
    return join(state, 'intra-deliver', 'quality.jsonl');
}

const isObject = (value) =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

// 記録の形式(版 1)の項目を全部確かめる。別の版の行を版 1 の規則で数えない
function isRow(value) {
    return (
        isObject(value) &&
        value.v === 1 &&
        typeof value.at === 'string' &&
        !Number.isNaN(Date.parse(value.at)) &&
        typeof value.worktree === 'string' &&
        typeof value.branch === 'string' &&
        typeof value.tool === 'string' &&
        typeof value.dirty === 'string' &&
        Number.isInteger(value.exit) &&
        typeof value.sec === 'number' &&
        isObject(value.cfg)
    );
}

// 壊れた行(JSON でない・版や項目が合わない)は飛ばし、行の番号を返す。時刻は行ごとに 1 回だけ読む
export function parseLog(text) {
    const rows = [];
    const broken = [];
    text.split('\n').forEach((line, index) => {
        if (line.trim() === '') {
            return;
        }
        let value;
        try {
            value = JSON.parse(line);
        } catch {
            broken.push(index + 1);
            return;
        }
        if (isRow(value)) {
            rows.push({ ...value, start: Date.parse(value.at) });
        } else {
            broken.push(index + 1);
        }
    });
    return { rows, broken };
}

function sameCfg(left = {}, right = {}) {
    const keys = new Set([...Object.keys(left), ...Object.keys(right)]);
    return [...keys].every((key) => left[key] === right[key]);
}

export function classify(start, end) {
    if (start.branch !== end.branch) {
        return '判定不能';
    }
    if (!sameCfg(start.cfg, end.cfg)) {
        return '無効';
    }
    return start.dirty === end.dirty ? '揺れ' : '有効';
}

// 道具が走らなかった実行(起動できない・シグナルで止まった)だけを件に数えない。終了コードの区分は 設計/コード構成/検査/結果の記録.md 6.6「結果の記録」
const notRun = (row) =>
    row.exit === 126 ||
    row.exit === 127 ||
    (row.exit >= 129 && row.exit <= 192);

function caseKey(row) {
    return `${row.worktree}\0${row.tool}`;
}

// 件とヒットなしの作り方は 設計/コード構成/検査/結果の記録.md 6.6。argv は件の鍵に入れない(絞った実行も同じ件。2026-10-16 の決定)。
// 並べ替えない: at は実行の始まりで、結果が分かる順は record が終わってから追記した順(ファイルの順)だけが持つ
export function walk(rows) {
    const open = new Map();
    const cases = [];
    const runs = [];
    for (const row of rows) {
        if (BATCH_TOOLS.has(row.tool)) {
            runs.push({ row, role: notRun(row) ? 'batch-not-run' : 'batch' });
            continue;
        }
        if (notRun(row)) {
            runs.push({ row, role: 'not-run' });
            continue;
        }
        const key = caseKey(row);
        if (row.exit !== 0) {
            runs.push({ row, role: 'case' });
            if (!open.has(key)) {
                open.set(key, row);
            }
            continue;
        }
        const start = open.get(key);
        if (start === undefined) {
            runs.push({ row, role: 'hitless' });
            continue;
        }
        open.delete(key);
        runs.push({ row, role: 'case' });
        cases.push({
            tool: row.tool,
            start,
            end: row,
            kind: classify(start, row),
        });
    }
    for (const start of open.values()) {
        cases.push({ tool: start.tool, start, end: null, kind: '未解消' });
    }
    return { runs, cases };
}

function emptyTotals() {
    return {
        runs: 0,
        hitless: 0,
        notRun: 0,
        sec: 0,
        kinds: Object.fromEntries(KINDS.map((kind) => [kind, 0])),
    };
}

// since は件の判定を全部の行で済ませてから当てる(区切りの前に開いた件を途中から数えない)
export function summarize(rows, since = null) {
    const { runs, cases } = walk(rows);
    const from = since === null ? -Infinity : since.getTime();
    const batchOf = (tool) => {
        if (!batches.has(tool)) {
            batches.set(tool, { count: 0, notRun: 0, sec: 0 });
        }
        return batches.get(tool);
    };
    const tools = new Map();
    const batches = new Map();
    const totalsOf = (tool) => {
        if (!tools.has(tool)) {
            tools.set(tool, emptyTotals());
        }
        return tools.get(tool);
    };
    for (const { row, role } of runs) {
        if (row.start < from) {
            continue;
        }
        if (role === 'batch' || role === 'batch-not-run') {
            const batch = batchOf(row.tool);
            batch[role === 'batch' ? 'count' : 'notRun'] += 1;
            batch.sec += row.sec;
            continue;
        }
        const totals = totalsOf(row.tool);
        totals.sec += row.sec;
        if (role === 'not-run') {
            totals.notRun += 1;
            continue;
        }
        totals.runs += 1;
        if (role === 'hitless') {
            totals.hitless += 1;
        }
    }
    for (const item of cases) {
        if (item.start.start >= from) {
            totalsOf(item.tool).kinds[item.kind] += 1;
        }
    }
    return { tools, batches };
}

function rate(numerator, denominator) {
    return denominator === 0
        ? '—'
        : `${Math.round((numerator / denominator) * 100)}%`;
}

const seconds = (value) => value.toFixed(1);

export function render({ tools, batches }) {
    const lines = [
        '| 道具 | 実行 | ヒットなし | ヒットなし率 | 有効 | 無効 | 無効率 | 揺れ | 判定不能 | 未解消 | 走らなかった | 秒 |',
        '|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
    ];
    for (const [tool, t] of [...tools].sort(([a], [b]) => a.localeCompare(b))) {
        const k = t.kinds;
        lines.push(
            `| ${tool} | ${t.runs} | ${t.hitless} | ${rate(t.hitless, t.runs)} | ${k['有効']} | ${k['無効']} | ` +
                `${rate(k['無効'], k['有効'] + k['無効'])} | ${k['揺れ']} | ${k['判定不能']} | ${k['未解消']} | ` +
                `${t.notRun} | ${seconds(t.sec)} |`,
        );
    }
    lines.push(
        '',
        '| 一括(秒は段の中の道具の秒を含む) | 回 | 走らなかった | 秒 |',
        '|---|---:|---:|---:|',
    );
    for (const [tool, batch] of [...batches].sort(([a], [b]) =>
        a.localeCompare(b),
    )) {
        lines.push(
            `| ${tool} | ${batch.count} | ${batch.notRun} | ${seconds(batch.sec)} |`,
        );
    }
    return `${lines.join('\n')}\n`;
}

function parseSince(value) {
    if (value === undefined) {
        return null;
    }
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    // 日付を作り直して一致を見る(2026-02-31 を次の月にずらさない)
    const date = parts && new Date(+parts[1], +parts[2] - 1, +parts[3]);
    if (
        !date ||
        date.getFullYear() !== +parts[1] ||
        date.getMonth() !== +parts[2] - 1 ||
        date.getDate() !== +parts[3]
    ) {
        throw new Error(`--since は YYYY-MM-DD: ${value}`);
    }
    return date;
}

function main() {
    let since;
    try {
        const { values } = parseArgs({
            args: argv.slice(2),
            options: { since: { type: 'string' } },
        });
        since = parseSince(values.since);
    } catch (error) {
        stderr.write(
            `quality-report: ${error.message}\n使い方: node scripts/quality/report.mjs [--since YYYY-MM-DD]\n`,
        );
        exit(2);
    }
    const path = logPath(env);
    let text;
    try {
        text = readFileSync(path, 'utf8');
    } catch (error) {
        stderr.write(
            `quality-report: 記録を読めない: ${path}: ${error.message}\n`,
        );
        exit(1);
    }
    const { rows, broken } = parseLog(text);
    for (const number of broken) {
        stderr.write(`quality-report: ${path}:${number}: 壊れた行を飛ばした\n`);
    }
    stdout.write(render(summarize(rows, since)));
}

// シンボリックリンク経由の起動でも main を走らせる。argv[1] が無い・実パスにできない(node -e など)ときは入口でない
function isEntrypoint() {
    try {
        return (
            argv[1] !== undefined &&
            realpathSync(argv[1]) === fileURLToPath(import.meta.url)
        );
    } catch {
        return false;
    }
}

if (isEntrypoint()) {
    main();
}
