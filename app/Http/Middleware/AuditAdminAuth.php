<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('audit.admin_password', '');
        if ($password === '') {
            return response('Private audit history is disabled. Set AUDIT_ADMIN_PASSWORD in .env and run php artisan config:cache.', 503);
        }

        $user = (string) $request->getUser();
        $provided = (string) $request->getPassword();

        if (! hash_equals('admin', $user) || ! hash_equals($password, $provided)) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Private SEO Audit History", charset="UTF-8"',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }
}
