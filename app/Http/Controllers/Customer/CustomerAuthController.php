<?php

namespace App\Http\Controllers\Customer;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CustomerAuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $credentials = $validator->validated();

        /** @var User|null $user */
        $user = User::query()
            ->where('email', $credentials['email'])
            ->with('role')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->jsonError('Invalid credentials.', null, 401);
        }

        if ($user->hasMinimumRole(Role::ADMIN)) {
            return $this->jsonError('Use the staff sign-in for this account.', null, 403);
        }

        if ($user->role?->name !== Role::USER) {
            return $this->jsonError('Invalid credentials.', null, 401);
        }

        if (! $user->is_active) {
            return $this->jsonError('Account suspended.', null, 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('customer-api')->plainTextToken;

        return $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->customerUserPayload($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $userRoleId = Role::query()->where('name', Role::USER)->value('id');

        if (! $userRoleId) {
            return $this->jsonError('Customer registration is not available (missing role configuration).', null, 500);
        }

        $fullName = trim($data['first_name'].' '.$data['last_name']);

        $user = User::query()->create([
            'name' => $fullName,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role_id' => $userRoleId,
            'is_active' => true,
            'permissions' => [],
        ]);

        $user->load('role');

        $token = $user->createToken('customer-api')->plainTextToken;

        return $this->jsonSuccess([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->customerUserPayload($user),
        ], 'Account created.', 201);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->currentAccessToken()?->delete();

        return $this->jsonSuccess(null, 'Logged out.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $email = (string) $validator->validated()['email'];

        /** @var User|null $user */
        $user = User::query()->with('role')->where('email', $email)->first();
        if ($user && $user->role?->name === Role::USER && $user->is_active) {
            PasswordFacade::broker('users')->sendResetLink(['email' => $email]);
        }

        // Do not reveal whether an email exists.
        return $this->jsonSuccess(null, 'If an account exists for this email, a reset link has been sent.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $payload = $validator->validated();

        $status = PasswordFacade::broker('users')->reset(
            [
                'email' => $payload['email'],
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                if ($user->role?->name !== Role::USER) {
                    return;
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordFacade::PASSWORD_RESET) {
            return $this->jsonError('Invalid or expired reset token.', null, 422);
        }

        return $this->jsonSuccess(null, 'Password has been reset successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function customerUserPayload(User $user): array
    {
        $user->loadMissing('role');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'vehicle_registration_number' => $user->vehicle_registration_number,
            'address' => $user->address,
            'city' => $user->city,
            'postcode' => $user->postcode,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
        ];
    }
}
