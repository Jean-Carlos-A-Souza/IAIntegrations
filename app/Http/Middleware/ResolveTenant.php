<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant) {
            TenantContext::setTenant($tenant);
            $this->resolver->applyTenantSchema($tenant);
        }

        return $next($request);
    }
}
