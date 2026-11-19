<?php

namespace GustavoLuis\ErpCore\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use JsonSerializable;

/**
 * Usuario autenticado por el token de auth-api, actuando en una empresa. Vive solo en memoria durante el pedido: los servicios NO
 * tienen una tabla de usuarios. El id es el `sub` del token (el id del usuario en auth-api) y las tablas de cada servicio lo
 * guardan como una referencia (`created_by`, `user_id`...), sin clave foránea.
 */
final class AuthenticatedUser implements Authenticatable, JsonSerializable
{
    /**
     * @param string|null  $name           Claim `name` del token (viene de auth-api). Puede faltar: los tokens emitidos
     *                                     antes de que auth-api lo agregara no lo traen. Es solo informativo (auditoría).
     * @param int|null     $organizationId Claim `org`: la empresa con la que trabaja la persona (dueña de los datos).
     *                                     null = token anterior a las empresas (ver organizationId()).
     * @param list<string> $permissions    Claim `permissions`: lo que la persona puede hacer en esa empresa.
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
        private readonly ?int $organizationId = null,
        private readonly array $permissions = [],
    ) {
    }

    /**
     * Empresa dueña de los datos de este pedido. Un token anterior a las empresas no trae `org`: se lo trata como
     * la empresa propia de la persona, que auth-api creó con el MISMO id que el usuario. Es una compatibilidad
     * transitoria (se apaga con AUTH_ACCEPT_LEGACY_TOKENS=false una vez que todas las sesiones se renovaron).
     */
    public function organizationId(): int
    {
        return $this->organizationId ?? $this->id;
    }

    /** true si el token trae la empresa explícita (false = token anterior a las empresas). */
    public function hasOrganizationClaim(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Si la persona puede hacer algo en su empresa. Un token anterior a las empresas no trae permisos: era el
     * dueño de sus propios datos y conserva lo que ya podía hacer (todo). Con `org` rige solo lo listado.
     */
    public function can(string $permission): bool
    {
        return $this->organizationId === null || in_array($permission, $this->permissions, true);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /** Nombre del usuario autenticado en esta petición, o null (consola, token viejo, sin sesión). */
    public static function currentName(): ?string
    {
        $user = auth()->user();

        return $user instanceof self ? $user->name : null;
    }

    /**
     * Empresa con la que trabaja el pedido en curso, o null si no hay una persona autenticada (consola, seeders).
     * Es lo que usan los scopes de empresa para filtrar.
     */
    public static function currentOrganizationId(): ?int
    {
        $user = auth()->user();

        return $user instanceof self ? $user->organizationId() : null;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return array{id: int, name?: string, organization_id: int, permissions?: list<string>} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            ...($this->name === null ? [] : ['name' => $this->name]),
            'organization_id' => $this->organizationId(),
            ...($this->organizationId === null ? [] : ['permissions' => $this->permissions]),
        ];
    }
}
