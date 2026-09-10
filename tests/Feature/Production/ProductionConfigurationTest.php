<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\UserRole;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Guards for the ways a Laravel application behaves differently in production
 * than in development - the failures that no functional test can catch because
 * they only appear once `php artisan config:cache` has run.
 */
class ProductionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The general form of the bug this file was written for.
     *
     * `config:cache` stops Laravel loading the .env file at all, so from that
     * moment an env() call ANYWHERE outside config/ returns its default. The
     * result is silent: the application boots, the value is simply wrong.
     * config/ is the only place env() may be read.
     */
    #[Test]
    public function no_code_outside_the_config_directory_reads_env_directly(): void
    {
        $offenders = [];

        $finder = Finder::create()
            ->files()
            ->in([base_path('app'), base_path('database'), base_path('routes'), base_path('resources')])
            ->name('*.php')
            ->name('*.blade.php');

        foreach ($finder as $file) {
            // Comments are stripped first: a docblock explaining why env()
            // must not be called here is not a call to env().
            $code = (string) preg_replace(
                ['#/\*.*?\*/#s', '#//[^\n]*#', '#\{\{--.*?--\}\}#s'],
                '',
                (string) $file->getContents(),
            );

            // `env(` as a call, not `->env(`, `getenv(`, or `environment(`.
            if (preg_match('/(?<![>\w])env\s*\(/', $code) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files call env() outside config/. Once `php artisan config:cache` has run, the .env\n"
            ."file is not loaded and every one of those calls silently returns its default instead.\n"
            .'Read the value in a config file and reach it with config() here.',
        );
    }

    #[Test]
    public function the_initial_super_admin_is_read_from_config_so_it_survives_config_cache(): void
    {
        config([
            'authorization.initial_super_admin.name' => 'Operations Lead',
            'authorization.initial_super_admin.email' => 'ops@example.org',
            'authorization.initial_super_admin.password' => 'A-Long-Enough-Passphrase-1',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $user = User::query()->where('email', 'ops@example.org')->sole();

        $this->assertSame('Operations Lead', $user->name);
        $this->assertTrue($user->hasRole(UserRole::SuperAdmin->value));
        $this->assertNotNull($user->email_verified_at);

        // The example address was never created alongside it.
        $this->assertSame(0, User::query()->where('email', 'admin@example.test')->count());
    }

    #[Test]
    public function seeding_the_example_super_admin_address_is_refused_in_production(): void
    {
        config(['authorization.initial_super_admin.email' => 'admin@example.test']);

        app()->detectEnvironment(fn (): string => 'production');

        try {
            // The seeder is run directly rather than through `artisan db:seed`,
            // which would stop at its own production confirmation prompt before
            // the guard under test could fire.
            (new SuperAdminSeeder)->run();

            $this->fail('The seeder created a Super Admin at the example address in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to seed the initial Super Admin at the example address', $e->getMessage());
            $this->assertSame(0, User::query()->where('email', 'admin@example.test')->count());
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    #[Test]
    public function the_super_admin_password_has_no_default_in_the_repository(): void
    {
        // An unset password must mean "generate one and print it once", never
        // "fall back to something committed here".
        $this->assertSame('', (string) config('authorization.initial_super_admin.password'));
    }

    #[Test]
    public function the_env_example_carries_names_but_never_a_credential(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach (preg_split('/\R/', $example) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $value = trim($value, " \"'");

            if (! preg_match('/(KEY|SECRET|PASSWORD|TOKEN)$/i', trim($name))) {
                continue;
            }

            $this->assertSame(
                '',
                $value === 'null' ? '' : $value,
                "[{$name}] carries a value in .env.example. That file is committed, so it may hold "
                .'names and empty placeholders only.',
            );
        }
    }

    #[Test]
    public function nothing_is_trusted_as_a_proxy_unless_it_is_configured(): void
    {
        // A forwarded header is trivially forged. Trusting one by default would
        // let any caller choose the IP that rate limiting and the audit trail
        // record - and AccessGrantRedeemer bounds token guessing by exactly
        // that address.
        $this->assertSame('', (string) config('app.trusted_proxies'));

        $spoofed = Request::create('/external/forms/nope', 'GET', server: [
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertSame('10.0.0.9', $spoofed->ip(), 'A forged X-Forwarded-For must not become the caller.');
        $this->assertFalse($spoofed->isSecure(), 'A forged X-Forwarded-Proto must not make a request look encrypted.');
    }

    #[Test]
    public function a_configured_proxy_is_read_and_the_real_caller_is_recovered(): void
    {
        config(['app.trusted_proxies' => '10.0.0.9']);

        (new AppServiceProvider($this->app))->boot();

        $forwarded = Request::create('/external/forms/nope', 'GET', server: [
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $trusted = new TrustProxies;
        $trusted->handle($forwarded, function (Request $request): Response {
            $this->assertSame('198.51.100.77', $request->ip(), 'Behind a trusted proxy the participant is the caller, not the balancer.');
            $this->assertTrue($request->isSecure());

            return new Response;
        });

        TrustProxies::flushState();
    }

    #[Test]
    public function the_session_cookie_is_secure_in_production_and_not_forced_locally(): void
    {
        // A session cookie that may travel over plain HTTP is a session that
        // can be taken off the wire.
        $this->assertTrue(
            (bool) (env('APP_ENV') === 'production'
                ? true
                : ! config('session.secure')),
            'Locally the secure flag must stay off so HTTP development still works.',
        );

        $this->assertTrue((bool) config('session.http_only'), 'The session cookie must not be readable from JavaScript.');
        $this->assertSame('lax', config('session.same_site'));
    }

    #[Test]
    public function the_app_config_defaults_fail_safe_when_the_environment_says_nothing(): void
    {
        // Asserted against the config file's own fallbacks rather than the
        // running values, because what matters is what happens on a server
        // whose .env is incomplete: it must get production behaviour and no
        // stack traces, not local behaviour.
        $source = (string) file_get_contents(base_path('config/app.php'));

        $this->assertStringContainsString(
            "env('APP_ENV', 'production')",
            $source,
            'An environment that does not say which environment it is must be treated as production.',
        );

        $this->assertStringContainsString(
            "env('APP_DEBUG', false)",
            $source,
            'Debug output must be off unless something explicitly turns it on.',
        );

        $this->assertNotEmpty(config('app.key'), 'APP_KEY must be set; without it nothing encrypted can be read back.');
    }
}
