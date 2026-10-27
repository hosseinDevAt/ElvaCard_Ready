<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceModeManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    public function __construct(
        protected MaintenanceModeManager $manager
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->manager->isActive()) {
            return $next($request);
        }

        // 1. Authenticated active staff administrators have unrestricted access
        $user = $request->user();
        if ($user !== null && $user->role === 'admin' && (bool) $user->is_active) {
            return $next($request);
        }

        // 2. Admin dashboard routes (unauthenticated users will be redirected to /login by auth middleware)
        if ($request->is('dashboard') || $request->is('dashboard/*')) {
            return $next($request);
        }

        // 3. Essential authentication endpoints
        if ($request->is('login') || $request->is('logout')) {
            return $next($request);
        }

        // 4. Livewire endpoints
        if ($request->is('livewire/*') || $request->is('livewire')) {
            if ($request->isMethod('GET')) {
                return $next($request);
            }

            if ($this->isAuthLivewireRequest($request)) {
                return $next($request);
            }
        }

        if (app()->environment('testing') && ($request->is('livewire-test/*') || $request->is('_livewire-test/*'))) {
            return $next($request);
        }

        // 5. Static assets and system health check
        if (
            $request->is('up') ||
            $request->is('build/*') ||
            $request->is('storage/*') ||
            $request->is('fonts/*') ||
            $request->is('favicon.*') ||
            $request->is('robots.txt')
        ) {
            return $next($request);
        }

        // 6. Return HTTP 503 Service Unavailable
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'سایت در حال حاضر در حال تعمیر و به‌روزرسانی است.',
            ], 503);
        }

        return response()->view('errors.503', [], 503, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    protected function isAuthLivewireRequest(Request $request): bool
    {
        $components = $request->input('components', []);

        if (! is_array($components) || empty($components)) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $snapshotRaw = $component['snapshot'] ?? '{}';
            $snapshot = json_decode((string) $snapshotRaw, true);
            $name = $snapshot['memo']['name'] ?? '';

            if ($name !== 'auth.login' && ! str_starts_with((string) $name, 'auth.')) {
                return false;
            }
        }

        return true;
    }
}
