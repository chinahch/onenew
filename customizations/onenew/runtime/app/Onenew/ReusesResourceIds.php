<?php

namespace App\Onenew;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ReusesResourceIds
{
    protected function performInsert(Builder $query)
    {
        return DB::transaction(function () use ($query) {
            ResourceIds::lock($this->getTable());
            $this->setAttribute('id', ResourceIds::firstGap($this->newQuery()->orderBy('id')->cursor()->map(fn ($row) => $row->id)));
            // Replicate/copy must never copy the source incarnation.
            $this->setAttribute('onenew_identity', (string) Str::uuid());
            if ($this->getTable() === 'v2_server') {
                ResourceIds::archiveStats((int) $this->id, 'legacy');
                ResourceIds::clearRuntime((int) $this->id);
            }
            return parent::performInsert($query);
        }, 3);
    }

    protected function performDeleteOnModel()
    {
        DB::transaction(function () {
            ResourceIds::lock($this->getTable());
            $current = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->first();
            if (!$current || $current->onenew_identity !== $this->onenew_identity) {
                throw new \RuntimeException('Resource was replaced; refresh before deleting');
            }
            if ($this->getTable() === 'v2_server') {
                ResourceIds::archiveStats((int) $this->id, (string) $this->onenew_identity);
                ResourceIds::clearRuntime((int) $this->id);
                // Do not let an old parent reference silently bind to a replacement.
                $this->newQuery()->where('parent_id', $this->id)->update(['parent_id' => null]);
            }
            parent::performDeleteOnModel();
        }, 3);
    }
}
