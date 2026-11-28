<?php
namespace Tests\Feature;

use App\Onenew\CloudBackup\BackupFailure;
use App\Onenew\CloudBackup\S3Client;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class S3ClientTest extends TestCase
{
    private function client(): S3Client
    {
        return new S3Client([
            'endpoint' => 'https://fixture.r2.cloudflarestorage.com',
            'bucket' => 'onenew-backup',
            'access_key_id' => 'fixture-access-key',
            'secret_access_key' => 'fixture-secret-key',
            'prefix' => 'backups',
        ]);
    }

    public function test_endpoint_validation_rejects_http_and_private_hosts(): void
    {
        self::assertTrue(S3Client::validEndpoint('https://abc123.r2.cloudflarestorage.com'));
        foreach ([
            'http://abc123.r2.cloudflarestorage.com',
            'https://127.0.0.1',
            'https://10.0.0.1',
            'https://localhost',
            'https://user:pass@abc123.r2.cloudflarestorage.com',
            'https://abc123.r2.cloudflarestorage.com:8443',
        ] as $bad) {
            self::assertFalse(S3Client::validEndpoint($bad), $bad);
        }
    }

    public function test_connection_signs_sigv4_and_lists_bucket(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'fixture.r2.cloudflarestorage.com/onenew-backup*' => Http::response('<ListBucketResult/>', 200),
        ]);
        $result = $this->client()->testConnection();
        self::assertSame('onenew-backup', $result['bucket']);
        Http::assertSent(fn($r) => str_starts_with($r->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=fixture-access-key/'));
    }

    public function test_single_put_upload_and_head_verification(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-test');
        file_put_contents($path, 'abcdef');
        $sha = hash('sha256', 'abcdef');
        $run = [
            'id' => 'fixture-run',
            'filename' => 'fixture.onm',
            'bytes' => 6,
            'sha256' => $sha,
            'revision' => str_repeat('a', 40),
        ];

        $uploaded = false;
        Http::fake(function ($r) use (&$uploaded, $sha) {
            if ($r->method() === 'PUT') {
                $uploaded = true;
                return Http::response('', 200, ['ETag' => '"etag-1"']);
            }
            if ($r->method() === 'HEAD') {
                return $uploaded
                    ? Http::response('', 200, ['Content-Length' => '6', 'x-amz-meta-onenew-sha256' => $sha])
                    : Http::response('', 404);
            }
            throw new \RuntimeException('Unexpected request: ' . $r->method() . ' ' . $r->url());
        });

        try {
            $persisted = [];
            $res = $this->client()->upload($path, $run, function ($p) use (&$persisted) {
                $persisted[] = $p;
            });
            self::assertSame('backups/fixture.onm', $res['key']);
            self::assertSame(6, $res['size']);
            self::assertSame('backups/fixture.onm', $persisted[0]['file_id']);
        } finally {
            unlink($path);
        }
    }

    public function test_verification_mismatch_throws_integrity_failed(): void
    {
        Http::fake([
            'fixture.r2.cloudflarestorage.com/onenew-backup/backups/fixture.onm' => Http::response('', 200, [
                'Content-Length' => '6',
                'x-amz-meta-onenew-sha256' => str_repeat('0', 64),
            ]),
        ]);
        try {
            $this->client()->verify('backups/fixture.onm', ['bytes' => 6, 'sha256' => hash('sha256', 'abcdef')]);
            self::fail('Expected BackupFailure');
        } catch (BackupFailure $e) {
            self::assertSame('integrity_failed', $e->reason);
        }
    }
}
