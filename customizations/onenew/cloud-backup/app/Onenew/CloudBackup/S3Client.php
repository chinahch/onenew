<?php
namespace App\Onenew\CloudBackup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class S3Client
{
    private const MULTIPART_THRESHOLD = 16777216; // 16 MiB
    private const PART_SIZE = 8388608;            // 8 MiB

    public function __construct(private array $config) {}

    public static function validEndpoint(string $url): bool
    {
        if (strlen($url) > 512) return false;
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https') return false;
        $host = strtolower($p['host'] ?? '');
        if ($host === '' || isset($p['user'], $p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment'])) return false;
        if (isset($p['port']) && (int) $p['port'] !== 443) return false;
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    public static function validBucket(string $bucket): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) === 1;
    }

    private function endpoint(): string
    {
        $ep = rtrim((string) ($this->config['endpoint'] ?? ''), '/');
        if (!self::validEndpoint($ep)) throw new BackupFailure('invalid_endpoint');
        return $ep;
    }

    private function bucket(): string
    {
        $bucket = trim((string) ($this->config['bucket'] ?? ''));
        if (!self::validBucket($bucket)) throw new BackupFailure('invalid_bucket');
        return $bucket;
    }

    private function region(): string
    {
        $region = trim((string) ($this->config['region'] ?? 'auto'));
        return preg_match('/^[a-z0-9-]{2,32}$/D', $region) ? $region : 'auto';
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    private function canonicalQuery(array $query): string
    {
        if (!$query) return '';
        ksort($query, SORT_STRING);
        $parts = [];
        foreach ($query as $k => $v) {
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $parts);
    }

    private function sign(string $method, string $path, array $query, string $body, array $extraHeaders = []): array
    {
        $endpoint = $this->endpoint();
        $parsed = parse_url($endpoint);
        $host = strtolower($parsed['host']);
        $region = $this->region();
        $accessKey = (string) ($this->config['access_key_id'] ?? '');
        $secretKey = (string) ($this->config['secret_access_key'] ?? '');
        if ($accessKey === '' || $secretKey === '') throw new BackupFailure('not_configured');

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $body);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        foreach ($extraHeaders as $k => $v) {
            $headers[strtolower($k)] = trim((string) $v);
        }
        ksort($headers, SORT_STRING);

        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . preg_replace('/\s+/', ' ', $v) . "\n";
            $signedHeaderNames[] = $k;
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        $encodedPath = '/' . $this->encodePath($path);
        $canonicalQuery = $this->canonicalQuery($query);
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $encodedPath,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $sendHeaders = [];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') $sendHeaders[$k] = $v;
        }
        $sendHeaders['Authorization'] = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $url = $endpoint . $encodedPath . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
        return [$url, $sendHeaders];
    }

    private function request(string $method, string $path, array $query = [], string $body = '', array $extraHeaders = [], array $allowed = [200], bool $retry = true)
    {
        $tries = $retry ? 3 : 1;
        for ($attempt = 0; $attempt < $tries; $attempt++) {
            [$url, $headers] = $this->sign($method, $path, $query, $body, $extraHeaders);
            $options = ['headers' => $headers];
            if ($body !== '') $options['body'] = $body;
            try {
                $response = Http::withoutRedirecting()->connectTimeout(10)->timeout(90)->send($method, $url, $options);
            } catch (ConnectionException) {
                if ($attempt + 1 === $tries) throw new BackupFailure('network_error');
                $this->wait($attempt);
                continue;
            }
            if (in_array($response->status(), $allowed, true)) return $response;
            if ($response->status() === 429 || $response->status() >= 500) {
                if ($attempt + 1 === $tries) throw new BackupFailure('remote_error');
                $this->wait($attempt);
                continue;
            }
            if ($response->status() === 401) throw new BackupFailure('authorization_expired');
            if ($response->status() === 403) throw new BackupFailure('permission_denied');
            if ($response->status() === 404) throw new BackupFailure('bucket_not_found');
            throw new BackupFailure('protocol_error');
        }
        throw new BackupFailure('remote_error');
    }

    private function wait(int $attempt): void
    {
        if (!app()->environment('testing')) usleep((1 << min($attempt, 3)) * 1000000);
    }

    public function testConnection(): array
    {
        $bucket = $this->bucket();
        $this->request('GET', $bucket, ['list-type' => '2', 'max-keys' => '1'], '', [], [200]);
        return ['bucket' => $bucket];
    }

    public function objectKey(string $filename): string
    {
        $prefix = trim((string) ($this->config['prefix'] ?? ''), '/');
        return $prefix !== '' ? $prefix . '/' . $filename : $filename;
    }

    public function verify(string $key, array $run): array
    {
        $bucket = $this->bucket();
        $r = $this->request('HEAD', $bucket . '/' . $key, [], '', [], [200, 404]);
        if ($r->status() === 404) throw new BackupFailure('integrity_failed');
        $len = $r->header('Content-Length');
        if ($len === '' || (int) $len !== (int) $run['bytes']) throw new BackupFailure('integrity_failed');
        $metaSha = strtolower((string) $r->header('x-amz-meta-onenew-sha256'));
        if ($metaSha !== '' && !hash_equals(strtolower((string) $run['sha256']), $metaSha)) {
            throw new BackupFailure('integrity_failed');
        }
        return ['key' => $key, 'size' => (int) $len];
    }

    public function upload(string $path, array $run, callable $persist): array
    {
        $bucket = $this->bucket();
        $key = $this->objectKey($run['filename']);
        $persist(['file_id' => $key]);
        $total = (int) $run['bytes'];
        $metaHeaders = [
            'content-type' => 'application/octet-stream',
            'x-amz-meta-onenew-run' => (string) $run['id'],
            'x-amz-meta-onenew-sha256' => (string) $run['sha256'],
            'x-amz-meta-onenew-revision' => (string) ($run['revision'] ?? ''),
        ];

        if ($total <= self::MULTIPART_THRESHOLD) {
            $body = file_get_contents($path);
            if ($body === false) throw new BackupFailure('local_error');
            $this->request('PUT', $bucket . '/' . $key, [], $body, $metaHeaders, [200, 201]);
            $persist(['uploaded_bytes' => $total, 'state' => 'verifying']);
            return $this->verify($key, $run);
        }

        $init = $this->request('POST', $bucket . '/' . $key, ['uploads' => ''], '', $metaHeaders, [200]);
        if (!preg_match('/<UploadId>([^<]+)<\/UploadId>/', $init->body(), $m)) {
            throw new BackupFailure('protocol_error');
        }
        $uploadId = trim($m[1]);
        $stream = fopen($path, 'rb');
        if (!$stream) throw new BackupFailure('local_error');

        $parts = [];
        $uploaded = 0;
        $partNumber = 1;
        try {
            while ($uploaded < $total) {
                $chunk = fread($stream, min(self::PART_SIZE, $total - $uploaded));
                if ($chunk === false || $chunk === '') throw new BackupFailure('local_error');
                $res = $this->request(
                    'PUT',
                    $bucket . '/' . $key,
                    ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId],
                    $chunk,
                    ['content-type' => 'application/octet-stream'],
                    [200]
                );
                $etag = trim((string) $res->header('ETag'));
                if ($etag === '') throw new BackupFailure('protocol_error');
                $parts[] = ['number' => $partNumber, 'etag' => $etag];
                $uploaded += strlen($chunk);
                $partNumber++;
                $persist(['uploaded_bytes' => $uploaded]);
            }

            $xml = '<CompleteMultipartUpload>';
            foreach ($parts as $part) {
                $etagXml = htmlspecialchars($part['etag'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= "<Part><PartNumber>{$part['number']}</PartNumber><ETag>{$etagXml}</ETag></Part>";
            }
            $xml .= '</CompleteMultipartUpload>';

            $this->request(
                'POST',
                $bucket . '/' . $key,
                ['uploadId' => $uploadId],
                $xml,
                ['content-type' => 'application/xml'],
                [200]
            );
            $persist(['uploaded_bytes' => $total, 'state' => 'verifying']);
            return $this->verify($key, $run);
        } catch (\Throwable $e) {
            try {
                $this->request('DELETE', $bucket . '/' . $key, ['uploadId' => $uploadId], '', [], [200, 204, 404], false);
            } catch (\Throwable) {
            }
            throw $e;
        } finally {
            fclose($stream);
        }
    }
}
