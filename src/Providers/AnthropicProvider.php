<?php

namespace Peralta\AgentKit\Providers;

use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\ProviderException;

class AnthropicProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function chat(
        array $messages,
        array $tools = [],
        ?string $system = null,
        array $options = []
    ): AgentResponse {
        $payload = [
            'model' => $options['model'] ?? $this->config['model'],
            'messages' => $this->formatMessages($messages),
            'max_tokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
        ];

        if ($system) {
            $payload['system'] = $system;
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        $data = $this->request('POST', 'messages', [
            'headers' => [
                'x-api-key' => $this->config['api_key'],
                'anthropic-version' => $this->config['anthropic_version'] ?? '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return $this->parseResponse($data);
    }

    protected function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            /** @var Message $msg */
            if ($msg->role === 'tool') {
                // Anthropic agrupa tool_result dentro de role=user
                $formatted[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $msg->toolCallId,
                        'content' => $msg->content,
                    ]],
                ];
                continue;
            }

            if ($msg->role === 'assistant' && !empty($msg->toolCalls)) {
                $contentBlocks = [];
                if ($msg->content) {
                    $contentBlocks[] = ['type' => 'text', 'text' => $msg->content];
                }
                foreach ($msg->toolCalls as $tc) {
                    $contentBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $tc->id,
                        'name' => $tc->name,
                        'input' => (object) $tc->arguments, // sempre object, mesmo se vazio
                    ];
                }
                $formatted[] = ['role' => 'assistant', 'content' => $contentBlocks];
                continue;
            }

            $formatted[] = [
                'role' => $msg->role,
                'content' => $msg->content ?? '',
            ];
        }

        return $formatted;
    }

    protected function formatTools(array $tools): array
    {
        return array_map(fn($tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'input_schema' => $tool->schema(),
        ], $tools);
    }

    protected function parseResponse(array $data): AgentResponse
    {
        if (!isset($data['content'])) {
            throw new ProviderException('Resposta sem content da Anthropic');
        }

        $textParts = [];
        $toolCalls = [];

        foreach ($data['content'] as $block) {
            if ($block['type'] === 'text') {
                $textParts[] = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    arguments: (array) ($block['input'] ?? []),
                );
            }
        }

        $stopReason = match ($data['stop_reason'] ?? 'end_turn') {
            'tool_use' => 'tool_use',
            'end_turn', 'stop_sequence' => 'stop',
            'max_tokens' => 'length',
            default => $data['stop_reason'],
        };

        return new AgentResponse(
            message: new Message(
                role: 'assistant',
                content: !empty($textParts) ? implode("\n", $textParts) : null,
                toolCalls: $toolCalls,
            ),
            stopReason: $stopReason,
            inputTokens: $data['usage']['input_tokens'] ?? null,
            outputTokens: $data['usage']['output_tokens'] ?? null,
            raw: $data,
        );
    }
}
