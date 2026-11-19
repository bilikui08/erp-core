<?php

namespace GustavoLuis\ErpCore\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Valida access tokens RS256 emitidos por auth-api (Laravel Passport) usando únicamente la clave pública. Los servicios nunca
 * emiten tokens ni guardan usuarios: el usuario autenticado (AuthenticatedUser) se arma en memoria con el `sub` del token.
 */
class PassportJwtGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        private readonly Request $request,
        private readonly string $publicKeyPath,
        private readonly bool $acceptLegacyTokens = false,
    ) {
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $bearer = $this->request->bearerToken();

        if (!$bearer) {
            return null;
        }

        $claims = $this->validatedClaims($bearer);

        return $this->user = $claims
            ? new AuthenticatedUser($claims['id'], $claims['name'], $claims['org'], $claims['permissions'])
            : null;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /** @return array{id: int, name: ?string, org: ?int, permissions: list<string>}|null Datos del token, solo si la firma y la vigencia son válidas. */
    private function validatedClaims(string $jwt): ?array
    {
        try {
            $token = (new Parser(new JoseEncoder()))->parse($jwt);

            if (!$token instanceof UnencryptedToken) {
                return null;
            }

            $valid = (new Validator())->validate(
                $token,
                new SignedWith(new Sha256(), InMemory::file($this->publicKeyPath)),
                new LooseValidAt($this->clock()),
            );

            $sub = $token->claims()->get('sub');

            if (!$valid || !ctype_digit((string) $sub)) {
                return null;
            }

            $organization = $this->organizationClaims($token);

            if ($organization === null) {
                return null;
            }

            return ['id' => (int) $sub, 'name' => $this->sanitizeName($token->claims()->get('name'))] + $organization;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Empresa y permisos del token. Fallan cerrado: un `org` mal formado, `permissions` sin `org`, o un token sin
     * empresa cuando ya no se aceptan, invalidan el token (la persona vuelve a iniciar sesión) en lugar de adivinar.
     * Lo que NO es una lista de permisos válidos se descarta (la persona no puede hacer nada de más).
     *
     * @return array{org: ?int, permissions: list<string>}|null
     */
    private function organizationClaims(UnencryptedToken $token): ?array
    {
        $claims = $token->claims();

        if (!$claims->has('org')) {
            if ($claims->has('permissions') || !$this->acceptLegacyTokens) {
                return null;
            }

            return ['org' => null, 'permissions' => []];
        }

        $org = $claims->get('org');

        if (!is_int($org) || $org < 1) {
            return null;
        }

        $permissions = [];

        if (is_array($claims->get('permissions'))) {
            foreach (array_slice($claims->get('permissions'), 0, 200) as $permission) {
                if (is_string($permission) && preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $permission)) {
                    $permissions[] = $permission;
                }
            }
        }

        return ['org' => $org, 'permissions' => array_values(array_unique($permissions))];
    }

    /**
     * El nombre es informativo (auditoría) y lo escribe otro servicio: se acepta solo si es texto, sin
     * caracteres de control y acotado, para no guardar basura en la base ni en los registros.
     */
    private function sanitizeName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $name = mb_substr($name, 0, 120);

        return $name === '' ? null : $name;
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
    }
}
