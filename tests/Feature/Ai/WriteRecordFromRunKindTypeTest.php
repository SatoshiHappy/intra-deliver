<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\PromptKinds;
use App\Actions\Ai\TranscriptionKinds;
use App\Enums\Ai\AiKindType;
use App\Models\Ai\AiRun;
use LogicException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * AI の書き込みの口が、実行の行の種類の型でその型の種類の一覧だけから種類を引くこと(docs の 設計/AI/公開の口.md 3.2 の呼べる時):
 * 文章・文字起こしの行はその型の一覧から引けて書ける。その型に登録が無い(ほかの型にだけ同じコードがある)ときと、会話の型の行は作りの誤り。
 */
final class WriteRecordFromRunKindTypeTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestConversationKinds;
    use UsesTestPromptKinds;
    use UsesTestTranscriptionKinds;
    use WritesRecordsFromRuns;

    private const OPERATION = 'AI(要約)';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->registerTestTranscriptionKind();
        $this->registerTestConversationKind();
        $this->recordingKind->writesTo = self::MODULE;
        $this->transcriptionKind->writesTo = self::MODULE;
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    public function test_a_prompt_run_is_written_with_its_prompt_kind(): void
    {
        $runId = $this->runningRun($this->noteRecord()->id);

        $this->assertTrue($this->writeDuringComplete($runId, self::OPERATION));
    }

    public function test_a_transcription_run_is_written_with_its_transcription_kind(): void
    {
        $runId = $this->transcriptionRun($this->noteRecord()->id, RecordingTranscriptionKind::CODE);

        $this->assertTrue($this->writeDuringComplete($runId, self::OPERATION));
    }

    /** その型に登録が無ければ、ほかの型に同じコードの種類があっても引かない(作りの誤り) */
    public function test_a_run_whose_code_is_registered_only_for_another_type_is_a_logic_error(): void
    {
        $recordId = $this->noteRecord()->id;
        $prompt = $this->runningRun($recordId, ['kind' => RecordingTranscriptionKind::CODE]);
        $transcription = $this->transcriptionRun($recordId, RecordingPromptKind::CODE);

        $this->assertThrows(
            fn () => $this->writeDuringComplete($prompt, self::OPERATION),
            LogicException::class,
            PromptKinds::notRegistered(RecordingTranscriptionKind::CODE),
        );
        $this->assertThrows(
            fn () => $this->writeDuringComplete($transcription, self::OPERATION),
            LogicException::class,
            TranscriptionKinds::notRegistered(RecordingPromptKind::CODE),
        );
    }

    public function test_a_conversation_run_is_a_logic_error(): void
    {
        $runId = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE])->id;

        $this->assertThrows(
            fn () => $this->writeDuringComplete($runId, self::OPERATION),
            LogicException::class,
            "会話の型の実行 {$runId}",
        );
    }

    /** レコードを対象にした処理中の文字起こしの型の実行(文章の実行の行を文字起こしの型の列に置き換える) */
    private function transcriptionRun(int $recordId, string $kindCode): int
    {
        return $this->runningRun($recordId, [
            'kind' => $kindCode,
            'kind_type' => AiKindType::Transcription,
            'audio_seconds' => '6.000',
            'original_input_upload_id' => 1,
            'max_output_tokens' => null,
            'budget' => 'transcription',
        ]);
    }
}
