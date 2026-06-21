<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsersController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('customers')) {
            return $this->jsonError('You do not have permission to view customers.', null, 403);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $users = User::query()
            ->with('role')
            ->orderBy('id')
            ->paginate($perPage);

        return $this->jsonSuccess([
            'users' => collect($users->items())->map(fn (User $u) => $this->userResource($u))->values()->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS) || ! $actor->hasPermission(Permission::ASSIGN_ROLES)) {
            return $this->jsonError('You do not have permission to create users.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'vehicle_registration_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in([Role::SUPER_ADMIN, Role::ADMIN, Role::USER])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $targetRole = Role::query()->where('name', $data['role'])->first();

        if (! $targetRole) {
            return $this->jsonError('Invalid role.', null, 422);
        }

        if ($data['role'] === Role::SUPER_ADMIN && ! $actor->isSuperAdmin()) {
            return $this->jsonError('Only a super admin can create super admin users.', null, 403);
        }

        if (! $actor->isSuperAdmin() && $actor->effectiveHierarchyRank() <= $targetRole->hierarchyRank()) {
            return $this->jsonError('You cannot assign a role equal to or above your own.', null, 403);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'vehicle_registration_number' => $data['vehicle_registration_number'] ?? null,
            'address' => $data['address'] ?? null,
            'password' => $data['password'],
            'role_id' => $targetRole->id,
            'permissions' => $data['permissions'] ?? [],
        ]);

        $user->load('role');

        return $this->jsonSuccess($this->userResource($user), 'User created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS)) {
            return $this->jsonError('You do not have permission to update users.', null, 403);
        }

        $user = User::query()->with('role')->find($id);

        if (! $user) {
            return $this->jsonError('User not found.', null, 404);
        }

        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return $this->jsonError('You cannot modify a super admin.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'vehicle_registration_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'string', Rule::in([Role::SUPER_ADMIN, Role::ADMIN, Role::USER])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        if (array_key_exists('role', $data)) {
            if (! $actor->hasPermission(Permission::ASSIGN_ROLES)) {
                return $this->jsonError('You do not have permission to assign roles.', null, 403);
            }

            $targetRole = Role::query()->where('name', $data['role'])->first();

            if (! $targetRole) {
                return $this->jsonError('Invalid role.', null, 422);
            }

            if ($data['role'] === Role::SUPER_ADMIN && ! $actor->isSuperAdmin()) {
                return $this->jsonError('Only a super admin can assign the super admin role.', null, 403);
            }

            if (! $actor->isSuperAdmin() && $actor->effectiveHierarchyRank() <= $targetRole->hierarchyRank()) {
                return $this->jsonError('You cannot assign a role equal to or above your own.', null, 403);
            }

            $user->role_id = $targetRole->id;
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }

        if (array_key_exists('vehicle_registration_number', $data)) {
            $user->vehicle_registration_number = $data['vehicle_registration_number'];
        }

        if (array_key_exists('address', $data)) {
            $user->address = $data['address'];
        }

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        if (array_key_exists('permissions', $data)) {
            $user->permissions = $data['permissions'];
        }

        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }

        $user->save();
        $user->load('role');

        return $this->jsonSuccess($this->userResource($user), 'User updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS)) {
            return $this->jsonError('You do not have permission to delete users.', null, 403);
        }

        $user = User::query()->with('role')->find($id);

        if (! $user) {
            return $this->jsonError('User not found.', null, 404);
        }

        if ($user->id === $actor->id) {
            return $this->jsonError('You cannot delete your own account.', null, 403);
        }

        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return $this->jsonError('You cannot delete a super admin.', null, 403);
        }

        if ($user->isSuperAdmin()) {
            $remaining = User::query()
                ->where('role_id', $user->role_id)
                ->where('id', '!=', $user->id)
                ->count();

            if ($remaining < 1) {
                return $this->jsonError('Cannot delete the last super admin.', null, 403);
            }
        }

        $user->tokens()->delete();
        $user->delete();

        return $this->jsonSuccess(null, 'User deleted successfully.');
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS)) {
            return $this->jsonError('You do not have permission to bulk update users.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $users = User::query()->with('role')->whereIn('id', $ids)->get();
        $allowedIds = $users
            ->filter(fn (User $u) => !($u->isSuperAdmin() && ! $actor->isSuperAdmin()) && $u->id !== $actor->id)
            ->pluck('id')
            ->all();

        $updated = 0;
        if (count($allowedIds) > 0) {
            $updated = User::query()->whereIn('id', $allowedIds)->update(['is_active' => (bool) $data['is_active']]);
        }

        return $this->jsonSuccess(['updated' => $updated], 'Users updated successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS)) {
            return $this->jsonError('You do not have permission to bulk delete users.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $ids = array_values(array_unique(array_map('intval', $validator->validated()['ids'])));
        $rows = User::query()->with('role')->whereIn('id', $ids)->get();

        $deletableIds = $rows
            ->filter(fn (User $u) => $u->id !== $actor->id && !($u->isSuperAdmin() && ! $actor->isSuperAdmin()))
            ->pluck('id')
            ->all();

        if (count($deletableIds) > 0) {
            User::query()->whereIn('id', $deletableIds)->each(function (User $u): void {
                $u->tokens()->delete();
                $u->delete();
            });
        }

        return $this->jsonSuccess([
            'deleted' => count($deletableIds),
            'skipped' => count($ids) - count($deletableIds),
        ], 'Users deleted successfully.');
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('customers')) {
            return $this->jsonError('You do not have permission to download template.', null, 403);
        }

        $rows = [
            ['name', 'email', 'phone', 'vehicle_registration_number', 'address', 'password', 'is_active'],
            ['John Doe', 'john@example.com', '+44 7700 900123', 'AB12 CDE', '1 Main Street, London', 'Password@123', 'active'],
        ];

        return $this->downloadSheet($rows, 'customers-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('customers')) {
            return $this->jsonError('You do not have permission to export customers.', null, 403);
        }

        $rows = User::query()
            ->with('role')
            ->whereHas('role', fn ($q) => $q->where('name', Role::USER))
            ->orderBy('id')
            ->get();

        $data = [['name', 'email', 'phone', 'vehicle_registration_number', 'address', 'is_active']];
        foreach ($rows as $row) {
            $data[] = [
                $row->name,
                $row->email,
                $row->phone ?? '',
                $row->vehicle_registration_number ?? '',
                $row->address ?? '',
                $row->is_active ? 'active' : 'inactive',
            ];
        }

        return $this->downloadSheet($data, 'customers-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission(Permission::MANAGE_USERS)) {
            return $this->jsonError('You do not have permission to import customers.', null, 403);
        }

        $validator = Validator::make($request->allFiles(), [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Invalid file. Upload CSV/XLSX/XLS (max 5MB).', null, 422, $validator->errors()->toArray());
        }

        $rows = $this->readUploadedRows($request->file('file')->getRealPath());
        if (count($rows) < 1) {
            return $this->jsonError('Uploaded file is empty.', null, 422);
        }

        $header = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $rows[0]);
        $expected = ['name', 'email', 'phone', 'vehicle_registration_number', 'address', 'password', 'is_active'];
        if ($header !== $expected) {
            return $this->jsonError('Header must be: '.implode(',', $expected), null, 422);
        }

        $userRole = Role::query()->where('name', Role::USER)->first();
        if (! $userRole) {
            return $this->jsonError('User role is missing.', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        foreach (array_slice($rows, 1) as $row) {
            $name = trim((string) ($row[0] ?? ''));
            $email = trim((string) ($row[1] ?? ''));
            $phone = trim((string) ($row[2] ?? ''));
            $vrn = trim((string) ($row[3] ?? ''));
            $address = trim((string) ($row[4] ?? ''));
            $password = trim((string) ($row[5] ?? ''));
            $activeRaw = mb_strtolower(trim((string) ($row[6] ?? 'active')));

            if ($name === '' || $email === '') {
                $skipped++;
                continue;
            }

            $isActive = !in_array($activeRaw, ['inactive', '0', 'false', 'no'], true);
            $existing = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

            if ($existing) {
                $existing->name = $name;
                $existing->phone = $phone !== '' ? $phone : null;
                $existing->vehicle_registration_number = $vrn !== '' ? mb_strtoupper($vrn) : null;
                $existing->address = $address !== '' ? $address : null;
                $existing->is_active = $isActive;
                if ($password !== '') {
                    $existing->password = $password;
                }
                if ($existing->role_id !== $userRole->id) {
                    $existing->role_id = $userRole->id;
                }
                $existing->save();
                $updated++;
                continue;
            }

            if ($password === '' || mb_strlen($password) < 8) {
                $skipped++;
                continue;
            }

            User::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone !== '' ? $phone : null,
                'vehicle_registration_number' => $vrn !== '' ? mb_strtoupper($vrn) : null,
                'address' => $address !== '' ? $address : null,
                'password' => $password,
                'role_id' => $userRole->id,
                'permissions' => [],
                'is_active' => $isActive,
            ]);
            $created++;
        }

        return $this->jsonSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Customer import completed.');
    }

    private function downloadSheet(array $rows, string $baseName): StreamedResponse
    {
        $format = mb_strtolower((string) request()->query('format', 'xlsx'));
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows): void {
                $out = fopen('php://output', 'w');
                if (! $out) {
                    return;
                }
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, "{$baseName}.csv", [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $sheet->setCellValueByColumnAndRow($c + 1, $r + 1, (string) $value);
            }
        }
        foreach (range(1, count($rows[0] ?? [])) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
        }, "{$baseName}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readUploadedRows(string $realPath): array
    {
        $spreadsheet = IOFactory::load($realPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rawRows = $sheet->toArray('', true, true, false);

        $rows = [];
        foreach ($rawRows as $row) {
            $normalized = array_map(fn ($v) => trim((string) $v), $row);
            if (count(array_filter($normalized, fn ($v) => $v !== '')) === 0) {
                continue;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'vehicle_registration_number' => $user->vehicle_registration_number,
            'address' => $user->address,
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
            ] : null,
            'permissions' => $user->isSuperAdmin() 
                ? ['dashboard', 'customers', 'vehicles', 'orders', 'payments', 'tyres', 'attributes', 'notifications', 'settings', 'test_dvla', 'api_settings', 'update']
                : ($user->permissions ?? []),
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
