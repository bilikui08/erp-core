<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use DateTimeImmutable;
use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use GustavoLuis\ErpCore\Tests\TestCase;
use Illuminate\Http\Request;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/** El guard valida los tokens RS256 de auth-api solo con la clave pública y falla cerrado ante cualquier duda. */
class GuardTest extends TestCase
{
    private string $dir;
    private string $private;
    private string $publicPath;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->private, $public] = $this->keyPair();
        $this->dir = sys_get_temp_dir() . '/erp-core-keys-' . getmypid();
        @mkdir($this->dir);
        $this->publicPath = $this->dir . '/oauth-public.key';
        file_put_contents($this->publicPath, $public);

        config(['auth.guards.api' => ['driver' => 'erp-jwt', 'public_key' => $this->publicPath]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicPath);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);

        return [$private, openssl_pkey_get_details($key)['key']];
    }

    /** @param array<string, mixed> $claims */
    private function token(array $claims = [], ?string $privateKey = null, string $expires = '+10 minutes', ?string $sub = '7'): string
    {
        $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($privateKey ?? $this->private), InMemory::plainText('x'));
        $builder = $config->builder()->issuedAt(new DateTimeImmutable('-1 minute'))->expiresAt(new DateTimeImmutable($expires));

        if ($sub !== null) {
            $builder = $builder->relatedTo($sub);
        }

        foreach ($claims as $name => $value) {
            $builder = $builder->withClaim($name, $value);
        }

        return $builder->getToken($config->signer(), $config->signingKey())->toString();
    }

    private function userFor(string $jwt): ?AuthenticatedUser
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]));
        $this->app['auth']->forgetGuards();

        $user = $this->app['auth']->guard('api')->user();

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    public function test_a_valid_token_gives_the_person_the_company_and_the_permissions(): void
    {
        $user = $this->userFor($this->token(['org' => 3, 'name' => 'Ana Pérez', 'permissions' => ['sales.invoice.view', 'sales.invoice.issue']]));

        $this->assertSame(7, $user->id);
        $this->assertSame('Ana Pérez', $user->name);
        $this->assertSame(3, $user->organizationId());
        $this->assertTrue($user->can('sales.invoice.issue'));
        $this->assertFalse($user->can('sales.invoice.annul'));
    }

    public function test_no_token_means_no_user(): void
    {
        $this->app->instance('request', Request::create('/'));
        $this->app['auth']->forgetGuards();

        $this->assertNull($this->app['auth']->guard('api')->user());
        $this->assertFalse($this->app['auth']->guard('api')->validate(['x' => 1]));
    }

    public function test_a_token_signed_with_another_key_is_refused(): void
    {
        [$other] = $this->keyPair();

        $this->assertNull($this->userFor($this->token(['org' => 3], $other)));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->assertNull($this->userFor($this->token(['org' => 3], expires: '-5 minutes')));
    }

    public function test_garbage_is_refused_without_breaking(): void
    {
        $this->assertNull($this->userFor('esto-no-es-un-jwt'));
        $this->assertNull($this->userFor(''));
    }

    public function test_the_subject_must_be_a_numeric_id(): void
    {
        $this->assertNull($this->userFor($this->token(['org' => 3], sub: 'abc')));
        $this->assertNull($this->userFor($this->token(['org' => 3], sub: null)));
    }

    public function test_a_malformed_company_claim_invalidates_the_token(): void
    {
        foreach (['3', 0, -1, 1.5, [3], null] as $org) {
            $this->assertNull($this->userFor($this->token(['org' => $org])), 'org = ' . json_encode($org));
        }
    }

    public function test_permissions_without_a_company_invalidate_the_token(): void
    {
        $this->assertNull($this->userFor($this->token(['permissions' => ['sales.invoice.view']])));
    }

    public function test_malformed_permissions_are_dropped_not_trusted(): void
    {
        $user = $this->userFor($this->token(['org' => 3, 'permissions' => ['ok.perm', 'MAYUS', '../x', 5, ['a'], 'ok.perm', str_repeat('a', 200), '']]));

        $this->assertSame(['ok.perm'], $user->permissions());
        $this->assertFalse($user->can('MAYUS'));
    }

    public function test_a_token_without_a_company_is_refused_unless_legacy_tokens_are_accepted(): void
    {
        $this->assertNull($this->userFor($this->token()), 'por defecto un servicio nuevo no acepta tokens sin empresa');

        config(['auth.guards.api.accept_legacy_tokens' => true]);
        $user = $this->userFor($this->token());

        $this->assertNotNull($user);
        $this->assertFalse($user->hasOrganizationClaim());
        $this->assertSame(7, $user->organizationId(), 'la empresa propia tiene el mismo id que la persona');
        $this->assertTrue($user->can('cualquier.cosa'), 'un token anterior a las empresas conserva todo lo que podía hacer');
    }

    public function test_the_name_claim_is_cleaned_before_it_is_stored_anywhere(): void
    {
        $this->assertSame('Ana Pérez', $this->userFor($this->token(['org' => 3, 'name' => "  Ana\nPérez  "]))->name);
        $this->assertNull($this->userFor($this->token(['org' => 3, 'name' => 123]))->name);
        $this->assertNull($this->userFor($this->token(['org' => 3, 'name' => "  \n "]))->name);
        $this->assertSame(120, mb_strlen($this->userFor($this->token(['org' => 3, 'name' => str_repeat('ñ', 300)]))->name));
    }

    public function test_the_user_serializes_without_leaking_anything_sensitive(): void
    {
        $json = (new AuthenticatedUser(7, 'Ana', 3, ['a.b']))->jsonSerialize();

        $this->assertSame(['id' => 7, 'name' => 'Ana', 'organization_id' => 3, 'permissions' => ['a.b']], $json);
        $this->assertSame(['id' => 7, 'organization_id' => 7], (new AuthenticatedUser(7))->jsonSerialize());
        $this->assertSame('', (new AuthenticatedUser(7))->getAuthPassword());
        $this->assertNull((new AuthenticatedUser(7))->getRememberToken());
    }
}
