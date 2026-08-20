<?php

declare(strict_types=1);

namespace Tamu\ArchipelagoLive;

use RuntimeException;

/**
 * Minimal JSON:API client for a live Archipelago.
 *
 * Deliberately dependency-free: no composer, no autoloader, no vendor/. These
 * scripts have to run on whatever self-hosted runner has line of sight to the
 * campus network, and "install PHP" is already one ask too many.
 *
 * Only ext-curl and ext-json are required, both of which ship enabled in every
 * mainstream PHP build.
 */
final class Client
{
    /**
     * Every entity this tool creates is named with this prefix.
     *
     * It is the ONLY thing standing between a sweep and someone's real
     * Metadata Display, so it stays long, ugly and unmistakable. The leading
     * zzz keeps probes at the bottom of the admin listing during the seconds
     * they exist.
     */
    public const PROBE_PREFIX = 'zzz-ci-probe-DELETE-ME-';

    private const RESOURCE = 'metadatadisplay_entity/metadatadisplay_entity';

    private string $base;

    public function __construct(
        string $baseUrl,
        private readonly string $user,
        private readonly string $pass,
        private readonly int $timeout = 30,
    ) {
        $this->base = rtrim($baseUrl, '/');
    }

    /**
     * Credentials come from the environment, never from a file in the repo.
     */
    public static function fromEnvironment(): self
    {
        $url = getenv('ARCHIPELAGO_URL') ?: '';
        $user = getenv('ARCHIPELAGO_USER') ?: '';
        $pass = getenv('ARCHIPELAGO_PASS') ?: '';

        $missing = [];
        foreach (['ARCHIPELAGO_URL' => $url, 'ARCHIPELAGO_USER' => $user, 'ARCHIPELAGO_PASS' => $pass] as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing environment variable(s): ' . implode(', ', $missing) . '.' . PHP_EOL
                . 'In CI these come from repository secrets; locally, export them in your shell.'
            );
        }

        return new self($url, $user, $pass);
    }

    /**
     * @param array<mixed>|null $payload
     *
     * @return array{status:int, body:?array<mixed>, raw:string, error:?string}
     */
    public function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ext-curl is required but not enabled in this PHP build.');
        }

        $handle = curl_init($this->base . $path);
        if ($handle === false) {
            throw new RuntimeException('Could not initialise a curl handle.');
        }

        $headers = ['Accept: application/vnd.api+json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/vnd.api+json';
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->user . ':' . $this->pass,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        if ($raw === false) {
            return ['status' => 0, 'body' => null, 'raw' => '', 'error' => $error ?? 'transport failure'];
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => (string) $raw,
            'error' => $error,
        ];
    }

    /**
     * Every Metadata Display entity on the site, following pagination.
     *
     * @return list<array{uuid:string, id:int, name:string, twig:string, changed:string}>
     */
    public function metadataDisplays(): array
    {
        $out = [];
        $path = '/jsonapi/' . self::RESOURCE . '?page%5Blimit%5D=50';

        while ($path !== null) {
            $response = $this->request('GET', $path);
            if ($response['status'] !== 200 || $response['body'] === null) {
                throw new RuntimeException($this->describeFailure('list metadata displays', $response));
            }

            foreach ($response['body']['data'] ?? [] as $record) {
                $attributes = $record['attributes'] ?? [];
                $out[] = [
                    'uuid' => (string) ($record['id'] ?? ''),
                    'id' => (int) ($attributes['drupal_internal__id'] ?? 0),
                    'name' => (string) ($attributes['name'] ?? ''),
                    'twig' => (string) ($attributes['twig'] ?? ''),
                    'changed' => (string) ($attributes['changed'] ?? ''),
                ];
            }

            $next = $response['body']['links']['next']['href'] ?? null;
            $path = is_string($next) ? substr($next, strlen($this->base)) : null;
        }

        return $out;
    }

    /**
     * Ask the live site to accept a template, by trying to save one.
     *
     * This is the whole point of the tool. The twig base field carries
     * format_strawberryfield's TwigTemplateConstraint, so entity validation --
     * which JSON:API runs on POST -- is the same gate the Metadata Display
     * edit form uses. 201 means Archipelago accepts the template; 422 means it
     * does not.
     *
     * @return array{status:int, uuid:?string, detail:?string, raw:string, error:?string}
     */
    public function probe(string $name, string $twig): array
    {
        $response = $this->request('POST', '/jsonapi/' . self::RESOURCE, [
            'data' => [
                'type' => 'metadatadisplay_entity--metadatadisplay_entity',
                'attributes' => [
                    'name' => $name,
                    'twig' => $twig,
                    'mimetype' => 'text/html',
                ],
            ],
        ]);

        return [
            'status' => $response['status'],
            'uuid' => isset($response['body']['data']['id']) ? (string) $response['body']['data']['id'] : null,
            'detail' => $this->violationDetail($response['body']),
            'raw' => $response['raw'],
            'error' => $response['error'],
        ];
    }

    public function delete(string $uuid): int
    {
        return $this->request('DELETE', '/jsonapi/' . self::RESOURCE . '/' . rawurlencode($uuid))['status'];
    }

    /**
     * Pull the twig-field violation out of a JSON:API error document.
     *
     * Archipelago sets useTwigMessage = FALSE on the constraint, so this is
     * always the generic 'Value is not a valid Twig template.' -- a verdict,
     * never a diagnosis. Getting the line number is the offline linter's job.
     *
     * @param array<mixed>|null $body
     */
    private function violationDetail(?array $body): ?string
    {
        foreach ($body['errors'] ?? [] as $error) {
            $pointer = $error['source']['pointer'] ?? '';
            if (str_contains((string) $pointer, '/attributes/twig')) {
                return trim((string) ($error['detail'] ?? ''));
            }
        }

        $first = $body['errors'][0]['detail'] ?? null;

        return $first !== null ? trim((string) $first) : null;
    }

    /**
     * @param array{status:int, body:?array<mixed>, raw:string, error:?string} $response
     */
    public function describeFailure(string $action, array $response): string
    {
        if ($response['error'] !== null) {
            return sprintf('Could not %s: %s', $action, $response['error']);
        }

        $detail = $this->violationDetail($response['body']) ?? substr($response['raw'], 0, 200);

        return sprintf('Could not %s: HTTP %d. %s', $action, $response['status'], $detail);
    }
}
