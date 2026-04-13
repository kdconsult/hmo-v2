<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;

class AdminPanelAuthenticate extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return route('filament.admin.auth.login', [
            'subdomain' => $this->resolveSubdomain($request),
        ]);
    }

    private function resolveSubdomain(Request $request): ?string
    {
        $subdomain = $request->route('subdomain');

        if (is_string($subdomain) && $subdomain !== '') {
            return $subdomain;
        }

        $host = $request->getHost();
        $appDomain = (string) config('app.domain');

        if ($appDomain === '' || $host === $appDomain) {
            return null;
        }

        $suffix = '.'.$appDomain;

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $candidate = substr($host, 0, -strlen($suffix));

        if ($candidate === '' || str_contains($candidate, '.')) {
            return null;
        }

        return $candidate;
    }
}
