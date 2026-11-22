<?php

namespace App\Domain\Publishing;

use App\Models\Domain;
use App\Models\Project;
use App\Models\Release;

/**
 * Maps a request Host (already validated by the framework's trusted-proxy rules; X-Forwarded-Host is
 * honoured only from configured proxies) to the live release of exactly one project.
 */
class SiteResolver
{
    /** @return array{project: Project, release: Release, domain: Domain}|null */
    public function resolve(string $rawHost): ?array
    {
        $host = Hostnames::normalize($rawHost);
        if ($host === null || Hostnames::isPlatformHost($host)) {
            return null;
        }
        $domain = Domain::query()->where('active_hostname', $host)->first();
        if ($domain === null || $domain->status !== 'active' || $domain->project_id === null) {
            return null;
        }
        $project = Project::query()->find($domain->project_id);
        if ($project === null || $project->live_release_id === null) {
            return null;
        }
        $release = Release::query()->find($project->live_release_id);
        if ($release === null || $release->status !== 'ready') {
            return null;
        }

        return ['project' => $project, 'release' => $release, 'domain' => $domain];
    }
}
