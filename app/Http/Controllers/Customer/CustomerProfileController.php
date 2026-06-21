<?php

namespace App\Http\Controllers\Customer;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomerProfileController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('role');

        return $this->jsonSuccess([
            'user' => $this->userPayload($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'vehicle_registration_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $vehicleRegistrationRaw = trim((string) ($data['vehicle_registration_number'] ?? ''));
        $vehicleRegistration = $vehicleRegistrationRaw !== ''
            ? mb_strtoupper($vehicleRegistrationRaw)
            : null;

        $phone = trim((string) ($data['phone'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $postcode = trim((string) ($data['postcode'] ?? ''));

        $user->update([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => $phone !== '' ? $phone : null,
            'vehicle_registration_number' => $vehicleRegistration,
            'address' => $address !== '' ? $address : null,
            'city' => $city !== '' ? $city : null,
            'postcode' => $postcode !== '' ? $postcode : null,
        ]);

        $user->refresh();
        $user->loadMissing('role');

        return $this->jsonSuccess([
            'user' => $this->userPayload($user),
        ], 'Profile updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
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

    public function updatePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($validator->validated()['current_password'], $user->password)) {
            return $this->jsonError('Current password is incorrect.', null, 422, [
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $validator->validated()['password'];
        $user->save();

        return $this->jsonSuccess(null, 'Password updated.');
    }
}
