<?php

declare(strict_types=1);

namespace ContentFlow\ShopwareAi\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ContentFlowClient
{
    private const CONFIG_PREFIX = 'ContentFlowShopwareAi.config.';

    public function __construct(
        private SystemConfigService $configuration,
        private HttpClientInterface $httpClient,
    ) {
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $apiUrl = rtrim((string) $this->configuration->get(self::CONFIG_PREFIX . 'apiUrl'), '/');
        $apiKey = trim((string) $this->configuration->get(self::CONFIG_PREFIX . 'apiKey'));

        if ('' === $apiUrl || '' === $apiKey) {
            throw new \RuntimeException('Configure the ContentFlow API URL and project API key first.');
        }

        $response = $this->httpClient->request('POST', $apiUrl . $path, [
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
            'json' => $payload,
        ]);

        /** @var array<string, mixed> $result */
        $result = $response->toArray(false);

        if ($response->getStatusCode() >= 400) {
            $message = $result['error']['message'] ?? 'ContentFlow request failed.';
            throw new \RuntimeException(\is_string($message) ? $message : 'ContentFlow request failed.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        $apiUrl = rtrim((string) $this->configuration->get(self::CONFIG_PREFIX . 'apiUrl'), '/');
        $apiKey = trim((string) $this->configuration->get(self::CONFIG_PREFIX . 'apiKey'));

        if ('' === $apiUrl || '' === $apiKey) {
            throw new \RuntimeException('Configure the ContentFlow API URL and project API key first.');
        }

        $response = $this->httpClient->request('GET', $apiUrl . $path, [
            'headers' => [
                'Accept' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
        ]);

        /** @var array<string, mixed> $result */
        $result = $response->toArray(false);

        if ($response->getStatusCode() >= 400) {
            $message = $result['error']['message'] ?? 'ContentFlow request failed.';
            throw new \RuntimeException(\is_string($message) ? $message : 'ContentFlow request failed.');
        }

        return $result;
    }

    public function provider(): string
    {
        return (string) ($this->configuration->get(self::CONFIG_PREFIX . 'provider') ?: 'openai');
    }

    public function setProvider(string $provider): void
    {
        $this->configuration->set(self::CONFIG_PREFIX . 'provider', $provider);
    }

    public function model(): ?string
    {
        $model = trim((string) $this->configuration->get(self::CONFIG_PREFIX . 'model'));

        return '' === $model ? null : $model;
    }
}
