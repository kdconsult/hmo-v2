<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetSubdomainUrlDefault
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($subdomain = $request->route('subdomain')) {
            URL::defaults(['subdomain' => $subdomain]);
        }

        return $next($request);
    }
}
