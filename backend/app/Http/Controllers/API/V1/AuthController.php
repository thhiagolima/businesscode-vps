<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request, TurnstileService $turnstile)
    {
        $rules = [
            'name'             => ['required', 'string', 'max:100'],
            'email'            => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->uncompromised()],
            'accept_terms'     => ['required', 'accepted'],
            'accept_privacy'   => ['required', 'accepted'],
        ];

        if ($turnstile->isEnabled()) {
            $rules['captcha_token'] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        if ($turnstile->isEnabled() && !$turnstile->verify($request->input('captcha_token'), $request->ip())) {
            return ApiResponse::error('Falha na verificação anti-bot. Tente novamente.', [], 422);
        }

        $clientIp = $request->ip();
        $consentVersion = config('business.terms_version', '1.0.0');

        return \Illuminate\Support\Facades\DB::transaction(function () use ($data, $clientIp, $consentVersion) {
            $freePlan = \App\Models\Plan::where('slug', 'free')->first();
            // Trial accounts start with the included balance of the free plan (in cents).
            // Default to R$ 5,00 (500 cents) when no free plan exists.
            $initialCents = (int) ($freePlan?->included_balance_cents ?? 500);
            $tenant = \App\Models\Tenant::create([
                'name'          => $data['name'] . "'s Workspace",
                'slug'          => \Illuminate\Support\Str::slug($data['name']) . '-' . \Illuminate\Support\Str::random(4),
                'plan_id'       => $freePlan?->id,
                'balance_cents' => $initialCents,
                'status'        => 'trial',
            ]);

            \App\Models\TenantChannel::provisionDefaults($tenant->id);

            $user = (new User())->forceFill([
                'name'                 => $data['name'],
                'email'                => $data['email'],
                'password'             => Hash::make($data['password']),
                'tenant_id'            => $tenant->id,
                'role'                 => 'admin',
                'lgpd_consented_at'    => now(),
                'lgpd_consent_version' => $consentVersion,
                'lgpd_consent_ip'      => $clientIp,
            ]);
            $user->save();

            $token = $user->createToken('auth')->plainTextToken;

            // Fire email verification notification if the feature is enabled.
            if (config('business.require_email_verification', false)) {
                $user->sendEmailVerificationNotification();
            }

            AuditLog::record('auth.register', 'User', $user->id, [
                'consent_version' => $consentVersion,
            ], $user->id, $tenant->id);

            return ApiResponse::success([
                'user' => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'email'  => $user->email,
                    'role'   => $user->role,
                    'email_verified' => (bool) $user->email_verified_at,
                    'tenant' => $tenant->only(['id', 'name', 'status', 'plan_id', 'balance_cents', 'credit_limit_cents', 'billing_status', 'billing_cycle_day']),
                ],
                'token' => $token,
                'email_verification_required' => (bool) config('business.require_email_verification', false),
            ], 'Conta criada com sucesso', [], 201);
        });
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();
        // Constant-time response: always run a bcrypt verify so the endpoint
        // cannot be used to enumerate registered emails.
        static $dummyHash = null;
        if ($dummyHash === null) {
            $dummyHash = Hash::make('__timing_decoy__');
        }
        $passwordOk = $user
            ? Hash::check($credentials['password'], $user->password)
            : (Hash::check($credentials['password'], $dummyHash) && false);

        if (!$user || !$passwordOk) {
            AuditLog::record('auth.login_failed', null, null, ['email' => $credentials['email']], null, null);
            return ApiResponse::error('Credenciais inválidas', [], 401);
        }

        if ($user->status === 'suspended') {
            AuditLog::record('auth.login_blocked_suspended', 'User', $user->id, null, $user->id, $user->tenant_id);
            return ApiResponse::error('Conta suspensa. Contate o suporte.', [], 403);
        }

        $token = $user->createToken('auth')->plainTextToken;

        $tenant = $user->tenant?->only(['id', 'name', 'status', 'plan_id', 'balance_cents', 'credit_limit_cents', 'billing_status', 'billing_cycle_day']);

        AuditLog::record('auth.login', 'User', $user->id, null, $user->id, $user->tenant_id);

        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'email_verified' => (bool) $user->email_verified_at,
                'force_password_reset' => (bool) $user->force_password_reset,
                'tenant' => $tenant,
            ],
            'token' => $token,
        ], 'Login realizado com sucesso');
    }

    public function logout(Request $request)
    {
        AuditLog::record('auth.logout');
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }
        return ApiResponse::success([], 'Logout realizado');
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $tenant = $user?->tenant?->only(['id', 'name', 'status', 'plan_id', 'balance_cents', 'credit_limit_cents', 'billing_status', 'billing_cycle_day']);
        return ApiResponse::success([
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'role' => $user?->role,
                'force_password_reset' => (bool) ($user?->force_password_reset),
                'tenant' => $tenant,
            ],
        ], 'OK');
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users')->ignore($user->id)],
        ]);
        $user->update($data);
        return ApiResponse::success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant' => $user->tenant?->only(['id', 'name', 'status', 'plan_id', 'balance_cents', 'credit_limit_cents', 'billing_status', 'billing_cycle_day']),
            ],
        ], 'Perfil atualizado');
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return ApiResponse::error('Senha atual incorreta', [], 422);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // P0R-04: revoga TODOS os tokens existentes do usuário pós troca de senha,
        // inclusive o token corrente — força re-login. Sem isso, um atacante que
        // tenha obtido o token via XSS continua autenticado mesmo após a vítima
        // trocar a senha.
        $user->tokens()->delete();

        AuditLog::record('auth.password_changed', 'User', $user->id, ['tokens_revoked' => true]);
        return ApiResponse::success([], 'Senha alterada com sucesso. Faça login novamente.');
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = \Illuminate\Support\Facades\Password::sendResetLink(
            $request->only('email')
        );

        if ($status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT) {
            return ApiResponse::success([], 'Link de redefinição enviado para o email');
        }

        return ApiResponse::error('Não foi possível enviar o link', [], 422);
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('Não autenticado.', [], 401);
        }
        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success([], 'Email já verificado.');
        }
        $user->sendEmailVerificationNotification();
        AuditLog::record('auth.verification_resent');
        return ApiResponse::success([], 'Email de verificação enviado.');
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::find($id);
        if (!$user) {
            return ApiResponse::error('Usuário não encontrado.', [], 404);
        }
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return ApiResponse::error('Token inválido.', [], 403);
        }
        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success([], 'Email já verificado.');
        }
        if ($user->markEmailAsVerified()) {
            AuditLog::record('auth.email_verified', 'User', $user->id, null, $user->id, $user->tenant_id);
        }
        return ApiResponse::success([], 'Email verificado com sucesso.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()],
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->update(['password' => Hash::make($password)]);
                $user->tokens()->delete();
            }
        );

        if ($status === \Illuminate\Support\Facades\Password::PASSWORD_RESET) {
            return ApiResponse::success([], 'Senha redefinida com sucesso');
        }

        return ApiResponse::error('Token inválido ou expirado', [], 422);
    }
}

