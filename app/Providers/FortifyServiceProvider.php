<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\UserStatus;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /** Fortify routes that carry a limiter of their own (config/fortify.php `limiters`), so the dispatcher leaves them alone. */
    private const OWN_LIMITER = ['login.store', 'two-factor.login.store', 'verification.send', 'verification.verify', 'passkey.login', 'passkey.confirm', 'passkey.store'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // R138 (Safeguarding queue): a suspended account's password still exists — only its
        // sessions, passkeys and remember-me token were revoked (`SuspendAccount`) — so credentials
        // alone must not be enough to issue a fresh session. Same shape as Fortify's own default
        // authenticator (`Hash::check` against the stored password), with the status check added;
        // a non-`Active` user and a wrong password fail identically, so login never discloses which.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where(Fortify::username(), $request->{Fortify::username()})->first();

            if ($user !== null && Hash::check((string) $request->password, $user->password) && $user->status === UserStatus::Active) {
                return $user;
            }

            return null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        // Keyed by the session's pending login; a session-less post falls back to the address, never to one shared key.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) ($request->session()->get('login.id') ?? $request->ip()));
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($this->emailAndAddress($request));
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        // R226: asking for a reset link and using one, 5 a minute each for an e-mail and address (two counters, so a slow
        // mailbox that needs a few requests does not lock out the form that sets the new password).
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by('password-reset|'.$this->emailAndAddress($request)));
        RateLimiter::for('password-reset-use', fn (Request $request) => Limit::perMinute(5)->by('password-reset-use|'.$this->emailAndAddress($request)));
        // R226: 5 sign-ups a minute per address; the tutor form uses this limiter on its own route, so the two forms have a counter each.
        RateLimiter::for('registration', fn (Request $request) => Limit::perMinute(5)->by('registration|'.$request->ip()));

        // Fortify's route file takes only a handful of per-route limiters from config, so every other route it registers
        // gets this one through `fortify.middleware`. A named limiter hashes its counter under the limiter's own name, which
        // is why each branch below carries its own key prefix (above); a safe method, and the routes that already have a
        // limiter of their own, pass untouched; every other write falls to the account baseline.
        RateLimiter::for('fortify', function (Request $request) {
            $name = $request->route()?->getName();

            return match (true) {
                $request->isMethodSafe(), in_array($name, self::OWN_LIMITER, true) => Limit::none(),
                $name === 'password.email' => RateLimiter::limiter('password-reset')($request),
                $name === 'password.update' => RateLimiter::limiter('password-reset-use')($request),
                $name === 'register.store' => RateLimiter::limiter('registration')($request),
                default => RateLimiter::limiter('account-writes')($request),
            };
        });
    }

    /** The lower-cased, transliterated sign-in name and the address; a non-string input (an array) counts as empty. */
    private function emailAndAddress(Request $request): string
    {
        $username = $request->input(Fortify::username());

        return Str::transliterate(Str::lower(is_string($username) ? $username : '').'|'.$request->ip());
    }
}
