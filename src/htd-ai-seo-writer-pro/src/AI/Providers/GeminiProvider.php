<?php
declare(strict_types=1);

namespace HTD\AISEO\AI\Providers;

use HTD\AISEO\AI\AIError;
use HTD\AISEO\AI\AIException;
use HTD\AISEO\AI\AIRequest;
use HTD\AISEO\AI\AIResponse;
use HTD\AISEO\AI\AbstractProviderAdapter;
use HTD\AISEO\AI\Capability;
use HTD\AISEO\AI\UsageMetadata;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Official Google Gemini generateContent API adapter.
 */
final class GeminiProvider extends AbstractProviderAdapter {
	public function getProviderId(): string {
		return 'gemini';
	}

	public function supportedCapabilities(): array {
		return [
			Capability::TEXT_GENERATION,
			Capability::STRUCTURED_OUTPUT,
			Capability::JSON_OUTPUT,
			Capability::VISION_INPUT,
			Capability::TOKEN_USAGE,
			Capability::MODEL_DISCOVERY,
		];
	}

	public function buildHttpPayload(AIRequest $request, string $apiKey): array {
		$systemParts = [];
		$contents = [];
		foreach ($request->messages as $msg) {
			if ($msg['role'] === 'system') {
				$systemParts[] = ['text' => $msg['content']];
			} else {
				$contents[] = [
					'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
					'parts' => [['text' => $msg['content']]],
				];
			}
		}
		$genConfig = ['maxOutputTokens' => $request->maxOutputTokens];
		if ($request->temperature !== null) {
			$genConfig['temperature'] = $request->temperature;
		}
		if (in_array(Capability::JSON_OUTPUT, $request->requiredCapabilities, true)) {
			$genConfig['responseMimeType'] = 'application/json';
		}
		$payload = [
			'contents'         => $contents,
			'generationConfig' => $genConfig,
		];
		if ($systemParts !== []) {
			$payload['systemInstruction'] = ['parts' => $systemParts];
		}

		$modelSlug = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', $request->model) ?? 'gemini-2.5-flash';
		return [
			'url'     => "https://generativelanguage.googleapis.com/v1beta/models/{$modelSlug}:generateContent",
			'method'  => 'POST',
			'headers' => [
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $apiKey,
			],
			'body'    => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	public function parseHttpResponse(int $status, string $body, AIRequest $request): AIResponse {
		if ($status < 200 || $status >= 300) {
			throw $this->mapHttpError($status, $body);
		}
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['candidates'][0])) {
			throw new AIException(AIError::INVALID_RESPONSE, 'Malformed Gemini response JSON.', $status);
		}
		$cand = $data['candidates'][0];
		$finish = (string) ($cand['finishReason'] ?? '');
		if ($finish === 'SAFETY' || $finish === 'RECITATION') {
			throw new AIException(AIError::CONTENT_FILTERED, "Gemini response blocked ({$finish}).", 422);
		}
		$text = (string) ($cand['content']['parts'][0]['text'] ?? '');
		$inTok = (int) ($data['usageMetadata']['promptTokenCount'] ?? 0);
		$outTok = (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0);
		$totTok = (int) ($data['usageMetadata']['totalTokenCount'] ?? ($inTok + $outTok));
		$cost = $this->models->estimateCostUsd('gemini', $request->model, $inTok, $outTok);

		return new AIResponse(
			providerId: 'gemini',
			model: $request->model,
			rawText: $text,
			parsedJson: $this->decodeJsonText($text),
			usage: new UsageMetadata($inTok, $outTok, $totTok, 'PROVIDER_REPORTED_TOKENS', $cost, 'ESTIMATED_COST'),
			finishReason: $finish !== '' ? $finish : null
		);
	}
}
