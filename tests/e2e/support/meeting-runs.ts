import type { Artisan } from './test';

// 会議の E2E(デモユーザー切替の持ち物の会議の spec)の、AI を呼ばずに会議の実行を終える道具と、議事録の区分の費用を足す道具(組の DB に掛ける。
// 組の DB を共有するほかの spec の待ちのジョブには触れない)。

/**
 * 会議の最後の対応の実行のジョブだけを、AI の応答の差し替え(fake = その PHP の文)で動かして消し、実行が expected の状態に
 * なったことを確かめる。失敗を流すときは、実行を失敗に書いた後に投げ直す例外を受け止め、状態が違えばその例外を添えて投げる。
 */
function runLastJob(
    artisan: Artisan,
    meetingId: number,
    fake: string,
    expected: 'succeeded' | 'failed',
) {
    const code = [
        fake,
        `$runId = App\\Models\\Meeting\\MeetingRun::query()->where("record_id", ${meetingId})`,
        '->orderByDesc("id")->value("ai_run_id");',
        '$found = null;',
        'foreach (DB::table("jobs")->orderBy("id")->get() as $row) {',
        '$command = unserialize(json_decode($row->payload, true)["data"]["command"]);',
        'if (($command instanceof App\\Jobs\\Ai\\RunPromptJob',
        ' || $command instanceof App\\Jobs\\Ai\\RunTranscriptionJob)',
        ' && $command->runId === $runId) {',
        '$found = [$row->id, $command]; break; } }',
        'if ($found === null) { throw new RuntimeException("実行 {$runId} の待ちのジョブが無い"); }',
        '$error = null;',
        'try { app()->call([$found[1], "handle"]); } catch (Throwable $thrown) { $error = $thrown; }',
        'DB::table("jobs")->where("id", $found[0])->delete();',
        '$status = App\\Models\\Ai\\AiRun::query()->findOrFail($runId)->status->value;',
        `if ($status !== ${JSON.stringify(expected)}) {`,
        `throw new RuntimeException("実行 {$runId} が ${expected} でなく {$status}", 0, $error); }`,
    ].join('');
    artisan(['tinker', '--execute', code]);
}

/**
 * 会議の最後の対応の議事録の実行を、議事録の出力の差し替え(PromptAgent::fake)で終える。
 */
export function completeLastRun(artisan: Artisan, meetingId: number) {
    const output =
        '{"title": "AI の件名", "summary": "E2E の要約です。", "topics": "## 決めたこと\\n- 要点です",' +
        ' "decisions": "なし", "open_issues": "なし", "next_actions": "なし"}';
    runLastJob(
        artisan,
        meetingId,
        `App\\Actions\\Ai\\PromptAgent::fake([json_decode(${JSON.stringify(output)}, true)]);`,
        'succeeded',
    );
}

/** 文字起こしの区間(文・話者のラベル・開始の秒・終了の秒) */
export type TranscribedSegment = [string, string, number, number];

/**
 * 会議の最後の対応の文字起こしの実行を、渡した区間の応答(Transcription::fake)で動かす(音声の変換は本物の ffmpeg)。
 * 区間が空なら文字起こしは失敗する(音声から書き起こせない)。
 */
export function transcribeLastRun(
    artisan: Artisan,
    meetingId: number,
    segments: TranscribedSegment[],
) {
    const fake = [
        `$segments = json_decode(${JSON.stringify(JSON.stringify(segments))}, true);`,
        'Laravel\\Ai\\Transcription::fake([new Laravel\\Ai\\Responses\\TranscriptionResponse(',
        'implode(" ", array_column($segments, 0)),',
        'new Illuminate\\Support\\Collection(array_map(fn (array $segment) =>',
        ' new Laravel\\Ai\\Responses\\Data\\TranscriptionSegment(',
        '$segment[0], $segment[1], (float) $segment[2], (float) $segment[3]), $segments)),',
        'new Laravel\\Ai\\Responses\\Data\\TranscriptionUsage(10, 5),',
        'new Laravel\\Ai\\Responses\\Data\\Meta("gemini", "gemini-3.5-transcribe"))]);',
    ].join('');
    runLastJob(
        artisan,
        meetingId,
        fake,
        segments.length === 0 ? 'failed' : 'succeeded',
    );
}

/**
 * 議事録の区分(default)に、日の上限と同じ額の終わった実行を 1 件足し、次の依頼を受けられない形にする。足した実行の id を返す
 * (筋の最後に {@link refundMinutesBudget} で消す。組の DB のほかの筋が AI に依頼できなくならないように)。
 */
export function spendMinutesBudget(artisan: Artisan): number {
    const code = [
        '$limit = config("ai_runs.budget.default.daily_usd");',
        'if (! is_numeric($limit)) { throw new RuntimeException("議事録の区分に日の上限が無い"); }',
        '$run = App\\Models\\Ai\\AiRun::factory()->succeeded(number_format((float) $limit, 6, ".", ""))',
        '->create(["requester_user_id" => App\\Models\\Master\\User::query()->orderBy("id")->value("id")]);',
        'echo "run={$run->id}";',
    ].join('');
    const id = /run=(\d+)/.exec(artisan(['tinker', '--execute', code]))?.[1];

    if (id === undefined) {
        throw new Error('足した実行の id を読めなかった');
    }

    return Number(id);
}

/** {@link spendMinutesBudget} で足した実行を消す */
export function refundMinutesBudget(artisan: Artisan, runId: number) {
    artisan([
        'tinker',
        '--execute',
        `if (App\\Models\\Ai\\AiRun::query()->whereKey(${runId})->delete() !== 1) {` +
            ` throw new RuntimeException("実行 ${runId} を消せなかった"); }`,
    ]);
}
