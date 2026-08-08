<?php

namespace Peralta\AgentKit\Providers;

use Peralta\AgentKit\DTOs\AgentResponse;
use Peralta\AgentKit\DTOs\Message;
use Peralta\AgentKit\DTOs\ToolCall;
use Peralta\AgentKit\Exceptions\ProviderException;

class GeminiProvider extends AbstractProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function chat(
        array $messages,
        array $tools = [],
        ?string $system = null,
        array $options = []
    ): AgentResponse {
        $model = $options['model'] ?? $this->config['model'];
        $apiKey = $this->config['api_key'];

        $payload = [
            'contents' => $this->formatMessages($messages),
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
            ],
        ];

        if (isset($options['temperature'])) {
            $payload['generationConfig']['temperature'] = $options['temperature'];
        }

        if ($system) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $system]],
            ];
        }

        if (!empty($tools)) {
            $payload['tools'] = [[
                'functionDeclarations' => $this->formatTools($tools),
            ]];
        }

        $data = $this->request('POST', "models/{$model}:generateContent?key={$apiKey}", [
            'headers' => ['Content-Type' => 'application/json'],
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
                $formatted[] = [
                    'role' => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name' => $msg->metadata['function_name'] ?? 'tool',
                            'response' => ['content' => $msg->content],
                        ],
                    ]],
                ];
                continue;
            }

            $role = $msg->role === 'assistant' ? 'model' : 'user';
            $parts = [];

            if ($msg->content) {
                $parts[] = ['text' => $msg->content];
            }

            foreach ($msg->toolCalls as $tc) {
                $parts[] = [
                    'functionCall' => [
                        'name' => $tc->name,
                        'args' => (object) $tc->arguments,
                    ],
                ];
            }

            if (empty($parts)) continue;

            $formatted[] = ['role' => $role, 'parts' => $parts];
        }

        return $formatted;
    }

    protected function formatTools(array $tools): array
    {
        return array_map(fn($tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $this->cleanSchemaForGemini($tool->schema()),
        ], $tools);
    }

    /**
     * Gemini é mais restrito: não aceita certos campos do JSON Schema.
     * Remove os incompatíveis (default, additionalProperties, $schema, etc.).
     */
    protected function cleanSchemaForGemini(array $schema): array
    {
        $disallowed = ['default', 'additionalProperties', '$schema', 'examples'];

        $clean = function ($node) use (&$clean, $disallowed) {
            if (!is_array($node)) return $node;
            foreach ($disallowed as $key) {
                unset($node[$key]);
            }
            foreach ($node as $k => $v) {
                $node[$k] = $clean($v);
            }
            return $node;
        };

        return $clean($schema);
    }

    protected function parseResponse(array $data): AgentResponse
    {
        $candidate = $data['candidates'][0] ?? null;
        if (!$candidate) {
            throw new ProviderException('Resposta sem candidates do Gemini');
        }

        $textParts = [];
        $toolCalls = [];

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $textParts[] = $part['text'];
            } elseif (isset($part['functionCall'])) {
                // Gemini não devolve ID — geramos um sintético
                $toolCalls[] = new ToolCall(
                    id: 'gemini_' . uniqid(),
                    name: $part['functionCall']['name'],
                    arguments: (array) ($part['functionCall']['args'] ?? []),
                );
            }
        }

        $finishReason = $candidate['finishReason'] ?? 'STOP';
        $stopReason = !empty($toolCalls) ? 'tool_use' : match ($finishReason) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            default => strtolower($finishReason),
        };

        return new AgentResponse(
            message: new Message(
                role: 'assistant',
                content: !empty($textParts) ? implode("\n", $textParts) : null,
                toolCalls: $toolCalls,
            ),
            stopReason: $stopReason,
            inputTokens: $data['usageMetadata']['promptTokenCount'] ?? null,
            outputTokens: $data['usageMetadata']['candidatesTokenCount'] ?? null,
            raw: $data,
        );
    }
}
