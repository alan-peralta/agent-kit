<?php

namespace Peralta\AgentKit\Providers;

use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;

class OpenAIProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function chat(
        array $messages,
        array $tools = [],
        ?string $system = null,
        array $options = []
    ): AgentResponse {
        $payload = [
            'model' => $options['model'] ?? $this->config['model'],
            'messages' => $this->formatMessages($messages, $system),
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $data = $this->request('POST', 'chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return $this->parseResponse($data);
    }

    protected function formatMessages(array $messages, ?string $system): array
    {
        $formatted = [];

        if ($system) {
            $formatted[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $msg) {
            /** @var Message $msg */
            if ($msg->role === 'tool') {
                $formatted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $msg->toolCallId,
                    'content' => $msg->content,
                ];
                continue;
            }

            $entry = ['role' => $msg->role];

            if ($msg->content !== null) {
                $entry['content'] = $msg->content;
            }

            if (!empty($msg->toolCalls)) {
                $entry['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->name,
                        'arguments' => json_encode($tc->arguments, JSON_UNESCAPED_UNICODE),
                    ],
                ], $msg->toolCalls);
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }

    protected function formatTools(array $tools): array
    {
        return array_map(fn($tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->schema(),
            ],
        ], $tools);
    }

    protected function parseResponse(array $data): AgentResponse
    {
        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            throw new \Peralta\AgentKit\Exceptions\ProviderException('Resposta sem choices da OpenAI');
        }

        $msg = $choice['message'];
        $finishReason = $choice['finish_reason'] ?? 'stop';

        $toolCalls = [];
        foreach ($msg['tool_calls'] ?? [] as $tc) {
            $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $toolCalls[] = new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: $args,
            );
        }

        $stopReason = match ($finishReason) {
            'tool_calls' => 'tool_use',
            'stop' => 'stop',
            'length' => 'length',
            default => $finishReason,
        };

        return new AgentResponse(
            message: new Message(
                role: 'assistant',
                content: $msg['content'] ?? null,
                toolCalls: $toolCalls,
            ),
            stopReason: $stopReason,
            inputTokens: $data['usage']['prompt_tokens'] ?? null,
            outputTokens: $data['usage']['completion_tokens'] ?? null,
            raw: $data,
        );
    }
}
