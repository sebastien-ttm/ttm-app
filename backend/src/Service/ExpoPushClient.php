<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExpoPushClient
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $pushLogger,
        private readonly string $expoAccessToken = '',
    ) {
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $data
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            fn ($t) => is_string($t) && str_starts_with($t, 'ExponentPushToken[')
        )));

        $stats = ['ok' => 0, 'failed' => 0, 'errors' => []];
        if ($tokens === []) {
            return $stats;
        }

        foreach (array_chunk($tokens, self::BATCH_SIZE) as $chunk) {
            $messages = array_map(fn ($t) => [
                'to' => $t,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'priority' => 'high',
                'channelId' => 'default',
            ], $chunk);

            $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
            if ($this->expoAccessToken !== '') {
                $headers['Authorization'] = 'Bearer '.$this->expoAccessToken;
            }

            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => $headers,
                    'json' => $messages,
                    'timeout' => 15,
                ]);

                $payload = $response->toArray(false);
                $tickets = $payload['data'] ?? [];

                foreach ($tickets as $ticket) {
                    if (($ticket['status'] ?? null) === 'ok') {
                        $stats['ok']++;
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = (string) ($ticket['message'] ?? 'unknown');
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed'] += count($chunk);
                $stats['errors'][] = $e->getMessage();
                $this->pushLogger->error('Expo push batch failed', ['exception' => $e]);
            }
        }

        $this->pushLogger->info('Expo push sent', $stats);
        return $stats;
    }
}
