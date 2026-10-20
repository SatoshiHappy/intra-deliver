<?php

namespace Database\Factories\Records;

use App\Models\Master\User;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordCommentMention>
 */
class RecordCommentMentionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_comment_id' => RecordComment::factory(),
            'user_id' => User::factory(),
        ];
    }
}
