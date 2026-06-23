<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ApiSetting;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandController extends Controller
{
    use ApiResponse;

    private const OPENAI_CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view brands.', null, 403);
        }

        $brands = Brand::query()->orderBy('name')->get()->map(fn (Brand $brand) => $this->resource($brand))->values();

        return $this->jsonSuccess(['brands' => $brands]);
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to download template.', null, 403);
        }

        $rows = [
            ['name', 'logo_url', 'is_active'],
            ['Michelin', 'https://example.com/logo.png', 'active'],
        ];

        return $this->downloadSheet($rows, 'brands_template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to export brands.', null, 403);
        }

        $rows = Brand::query()->orderBy('name')->get();
        $data = [['name', 'logo_url', 'is_active']];

        foreach ($rows as $brand) {
            $data[] = [
                $brand->name,
                $brand->logo_url ?? '',
                $brand->is_active ? 'active' : 'inactive',
            ];
        }

        return $this->downloadSheet($data, 'brands_export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to import brands.', null, 403);
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

        $normalizedHeader = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $rows[0]);
        if ($normalizedHeader !== ['name', 'logo_url', 'is_active']) {
            return $this->jsonError('Header must be: name,logo_url,is_active', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $name = trim((string) ($row[0] ?? ''));
            $logoUrl = trim((string) ($row[1] ?? ''));
            $isActiveRaw = mb_strtolower(trim((string) ($row[2] ?? 'active')));

            if ($name === '') {
                $skipped++;
                continue;
            }

            $isActive = !in_array($isActiveRaw, ['inactive', '0', 'false', 'no'], true);

            $existing = Brand::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            if ($existing) {
                $existing->logo_url = $logoUrl !== '' ? $logoUrl : null;
                $existing->is_active = $isActive;
                $existing->save();
                $updated++;
            } else {
                Brand::create([
                    'name' => $name,
                    'logo_url' => $logoUrl !== '' ? $logoUrl : null,
                    'is_active' => $isActive,
                ]);
                $created++;
            }
        }

        return $this->jsonSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Brand import completed.');
    }

    public function generateWithAi(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to generate brands.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'country' => ['required', 'string', 'max:120'],
            'count' => ['required', 'integer', Rule::in([5, 10, 20])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $setting = ApiSetting::query()->where('key_name', 'openai')->first();
        $apiKey = trim((string) ($setting?->value ?? ''));
        if ($apiKey === '') {
            return $this->jsonError('Workatmo AI is inactive.', null, 422);
        }
        if (! $setting?->is_enabled) {
            return $this->jsonError('Workatmo AI is inactive.', null, 422);
        }

        $country = trim((string) $request->input('country'));
        $count = (int) $request->input('count');

        $prompt = $this->buildBrandPrompt($country, $count);
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You generate realistic tyre brand names. Return strict JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.4,
        ];

        $raw = $this->postJsonWithBearer(self::OPENAI_CHAT_URL, $apiKey, $payload, 30);
        if (! $raw['ok']) {
            return $this->jsonError($raw['error'] ?? 'Failed to generate brands via Workatmo AI.', null, 502, [
                'http_code' => $raw['http_code'] ?? 0,
            ]);
        }

        $choices = $raw['body']['choices'][0]['message']['content'] ?? '';
        $decoded = $this->decodeJsonContent((string) $choices);
        if (! is_array($decoded) || ! isset($decoded['brands']) || ! is_array($decoded['brands'])) {
            return $this->jsonError('Workatmo AI returned an unexpected response format.', null, 502);
        }

        $normalized = [];
        foreach ($decoded['brands'] as $item) {
            $name = trim((string) $item);
            if ($name !== '') {
                $normalized[] = preg_replace('/\s+/', ' ', $name);
            }
        }
        $normalized = array_values(array_unique($normalized));

        if (count($normalized) === 0) {
            return $this->jsonError('No brands were generated. Please try again.', null, 502);
        }

        return $this->jsonSuccess([
            'country' => $country,
            'count' => $count,
            'brands' => array_slice($normalized, 0, $count),
        ], 'Brands generated successfully.');
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

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to create brands.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', 'unique:brands,name'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $brand = Brand::query()->create([
            'name' => $data['name'],
            'logo_url' => $data['logo_url'] ?? null,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
        ]);

        return $this->jsonSuccess($this->resource($brand), 'Brand created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to update brands.', null, 403);
        }

        $brand = Brand::query()->find($id);
        if (! $brand) {
            return $this->jsonError('Brand not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('brands', 'name')->ignore($brand->id)],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $brand->name = $data['name'];
        $brand->logo_url = $data['logo_url'] ?? null;
        $brand->is_active = isset($data['is_active']) ? (bool) $data['is_active'] : $brand->is_active;
        $brand->save();

        return $this->jsonSuccess($this->resource($brand), 'Brand updated successfully.');
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to upload brand logos.', null, 403);
        }

        $validator = Validator::make($request->allFiles(), [
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError(
                'Invalid file. Allowed: JPG, JPEG, PNG. Max size: 2MB.',
                null,
                422,
                $validator->errors()->toArray()
            );
        }

        $file = $request->file('logo');
        $path = $file->store('brand-logos', 'public');
        $url = Storage::disk('public')->url($path);

        return $this->jsonSuccess(['url' => $url], 'Brand logo uploaded successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to delete brands.', null, 403);
        }

        $brand = Brand::query()->find($id);
        if (! $brand) {
            return $this->jsonError('Brand not found.', null, 404);
        }

        $hasTyres = DB::table('tyres')->where('brand_id', $brand->id)->exists();
        if ($hasTyres) {
            return $this->jsonError('Cannot delete brand because it is used by one or more tyres.', null, 409);
        }

        $brand->delete();

        return $this->jsonSuccess(null, 'Brand deleted successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk delete brands.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $tyreCountsByBrand = DB::table('tyres')
            ->select('brand_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('brand_id', $ids)
            ->groupBy('brand_id')
            ->pluck('aggregate', 'brand_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $blockedIds = array_values(array_map(
            fn ($brandId) => (int) $brandId,
            array_keys(array_filter($tyreCountsByBrand, fn (int $count) => $count > 0))
        ));
        $deletableIds = array_values(array_diff($ids, $blockedIds));

        DB::transaction(function () use ($deletableIds): void {
            if (count($deletableIds) === 0) {
                return;
            }

            $brands = Brand::query()->whereIn('id', $deletableIds)->get();
            foreach ($brands as $brand) {
                if ($brand->logo_url && str_contains($brand->logo_url, '/storage/brand-logos/')) {
                    $relativePath = 'brand-logos/' . basename($brand->logo_url);
                    Storage::disk('public')->delete($relativePath);
                }
            }

            Brand::query()->whereIn('id', $deletableIds)->delete();
        });

        $deletedCount = count($deletableIds);
        $blockedCount = count($blockedIds);
        $message = $blockedCount > 0
            ? "Deleted {$deletedCount} brand(s). Skipped {$blockedCount} brand(s) because they are used by tyres."
            : 'Brands deleted successfully.';

        return $this->jsonSuccess([
            'deleted' => $deletedCount,
            'skipped' => $blockedCount,
            'blocked_ids' => $blockedIds,
        ], $message);
    }

    private function resource(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'logo_url' => $brand->logo_url,
            'is_active' => (bool) $brand->is_active,
            'created_at' => $brand->created_at?->toIso8601String(),
            'updated_at' => $brand->updated_at?->toIso8601String(),
        ];
    }

    private function buildBrandPrompt(string $country, int $count): string
    {
        return "Generate {$count} tyre brand names for the country {$country}. "
            .'Return STRICT JSON with this exact shape: {"brands":["Brand 1","Brand 2"]}. '
            .'Rules: return only an array of strings, no duplicates, no markdown, no commentary.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, body: array<string, mixed>}|array{ok: false, error: string, http_code?: int}
     */
    private function postJsonWithBearer(string $url, string $token, array $payload, int $timeoutSeconds = 25): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => 'Workatmo AI request failed: '.$curlError];
        }

        $body = json_decode($raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'error' => 'Workatmo AI request failed.',
                'http_code' => $httpCode,
            ];
        }

        if (! is_array($body)) {
            return ['ok' => false, 'error' => 'Workatmo AI returned invalid JSON.', 'http_code' => $httpCode];
        }

        return ['ok' => true, 'body' => $body];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonContent(string $content): ?array
    {
        $clean = trim($content);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-zA-Z0-9]*\s*/', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $decoded = json_decode($clean, true);

        return is_array($decoded) ? $decoded : null;
    }
}
