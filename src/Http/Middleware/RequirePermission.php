<?php

namespace GustavoLuis\ErpCore\Http\Middleware;

use Closure;
use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la persona tenga permiso en su empresa. Uso en rutas: `->middleware('permission:sales.invoice.issue')`;
 * con varios, alcanza con UNO (`permission:sales.invoice.view,sales.invoice.issue`). Se chequean permisos, nunca roles.
 * Responde 403 (no 401: la persona está autenticada y la sesión sigue válida).
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user instanceof AuthenticatedUser) {
            foreach ($permissions as $permission) {
                if ($user->can($permission)) {
                    return $next($request);
                }
            }
        }

        return $this->forbidden($permissions);
    }

    /** Mismo formato de error que el resto de las respuestas de los servicios (`success`, `message`, `code`, `errors`). */
    private function forbidden(array $permissions): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'No tenés permiso para realizar esta acción.',
            'code' => 'FORBIDDEN',
            'trace' => null,
            'errors' => ['permission' => $permissions],
        ], 403);
    }
}
