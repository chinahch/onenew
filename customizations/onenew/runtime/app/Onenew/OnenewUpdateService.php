<?php

namespace App\Onenew;

use App\Services\UpdateService;
use Illuminate\Support\Facades\Cache;

// The runtime is an immutable image. Updating its inherited .git directory
// would overwrite the overlay and would report the wrong upstream version.
class OnenewUpdateService extends UpdateService
{
    protected function getCurrentCommit(): string
    {
        $revision = trim((string) @file_get_contents('/opt/onenew/REVISION'));
        return preg_match('/^[a-f0-9]{40}$/', $revision) ? substr($revision, 0, 7) : 'local';
    }

    public function getCurrentVersion(): string
    {
        return 'onenew-' . $this->getCurrentCommit();
    }

    public function updateVersionCache(): void
    {
        Cache::forever(self::CACHE_VERSION, $this->getCurrentCommit());
        Cache::forever(self::CACHE_VERSION_DATE, date('Ymd'));
    }

    public function checkForUpdates(): array
    {
        return [
            'has_update' => false,
            'latest_version' => $this->getCurrentVersion(),
            'current_version' => $this->getCurrentVersion(),
            'update_logs' => [],
            'download_url' => 'https://github.com/chinahch/onenew',
            'published_at' => '',
            'author' => 'chinahch',
            'message' => '通过项目仓库拉取源码并重建容器更新。',
        ];
    }

    public function executeUpdate(): array
    {
        return ['success' => false, 'message' => '请先备份，在宿主机 git pull --ff-only 后执行 docker compose up -d --build；不要在运行中的容器内覆盖源码。'];
    }
}
