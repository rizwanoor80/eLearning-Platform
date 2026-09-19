<?php

namespace App\Actions\Page;

use App\Actions\RecordAuditLog;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Publishing is the only way a page changes (DATA_MODEL has no draft state):
 * one transaction writes a `page_versions` row, bumps `pages.version` and
 * updates the live title/body. The page row is locked first, so two admins
 * publishing at once are serialised instead of computing the same next version.
 * Nothing is written when the content is unchanged.
 */
class PublishPage
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    public function __invoke(User $admin, Page $page, string $title, string $body): ?PageVersion
    {
        return DB::transaction(function () use ($admin, $page, $title, $body): ?PageVersion {
            $locked = Page::query()->lockForUpdate()->findOrFail($page->id);

            if ($locked->title === $title && $locked->body === $body) {
                return null;
            }

            $version = $locked->version + 1;
            $now = now();

            $published = $locked->versions()->create([
                'version' => $version,
                'title' => $title,
                'body' => $body,
                'published_by' => $admin->id,
                'published_at' => $now,
            ]);

            $before = ['version' => $locked->version, 'title' => $locked->title];

            $locked->forceFill([
                'title' => $title,
                'body' => $body,
                'version' => $version,
                'published_at' => $now,
                'updated_by' => $admin->id,
            ])->save();

            ($this->recordAuditLog)($admin, 'page.published', $locked, $before, ['version' => $version, 'title' => $title]);

            $page->setRawAttributes($locked->getAttributes(), true);

            return $published;
        });
    }
}
