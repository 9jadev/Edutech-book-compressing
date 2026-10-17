<?php

namespace Tests\Unit;

use App\Services\Lessons\ModelReply;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use PHPUnit\Framework\TestCase;

class ModelReplyTest extends TestCase
{
    private function response(FinishReason $reason): AgentResponse
    {
        $usage = new TextUsage(1000, 8192);
        $response = new AgentResponse('inv-1', '{"pages": [{"page": "a", "blocks": [', $usage, new Meta('qwen', 'deepseek-v4.1-flash'));
        $response->steps = collect([new Step('{"pages": [', [], [], $reason, $usage, new Meta('qwen', 'deepseek-v4.1-flash'), '', [])]);

        return $response;
    }

    public function test_it_reads_why_the_model_stopped()
    {
        $this->assertSame('length', ModelReply::finishReason($this->response(FinishReason::Length)));
        $this->assertSame('stop', ModelReply::finishReason($this->response(FinishReason::Stop)));
    }

    public function test_a_cut_off_reply_gets_a_clear_error_and_a_finished_one_does_not()
    {
        $this->assertSame(
            'Reply cut off: the model hit its output-token limit after 8192 output tokens (finish_reason=length), so the JSON is incomplete. Send fewer pages per call or raise the model\'s max output tokens.',
            ModelReply::truncation(['finish_reason' => 'length', 'output_tokens' => 8192]),
        );
        $this->assertNull(ModelReply::truncation(['finish_reason' => 'stop', 'output_tokens' => 900]));
        $this->assertNull(ModelReply::truncation(null));
    }

    public function test_the_raw_reply_is_kept_only_for_failed_calls()
    {
        $reply = ['text' => '{"pages": [', 'finish_reason' => 'length'];

        $this->assertSame(['finish_reason' => 'length', 'reply' => '{"pages": ['], ModelReply::logFields($reply, false));
        $this->assertSame(['finish_reason' => 'length'], ModelReply::logFields($reply, true));
        $this->assertSame(['finish_reason' => null], ModelReply::logFields(null, false));

        $long = ModelReply::logFields(['text' => str_repeat('x', ModelReply::MAX_SAVED_CHARS + 5)], false)['reply'];
        $this->assertStringEndsWith('… [5 more characters]', $long);
    }
}
