<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\FuelEfficiency;
use App\Models\ApiSetting;
use App\Models\Season;
use App\Models\Size;
use App\Models\SpeedRating;
use App\Models\Tyre;
use App\Models\TyreType;
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

class TyresController extends Controller
{
    use ApiResponse;
    private const OPENAI_CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to view tyres.', null, 403);
        }

        $rows = Tyre::query()
            ->with(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating'])
            ->latest('id')
            ->get()
            ->map(fn (Tyre $tyre) => $this->resource($tyre))
            ->values();

        return $this->jsonSuccess(['tyres' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to create tyres.', null, 403);
        }

        $validator = $this->validator($request, false);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $status = $this->normalizeStatus($data['status'] ?? true);

        $tyre = Tyre::query()->create([
            'brand_id' => (int) $data['brand_id'],
            'model' => trim((string) $data['model']),
            'size_id' => (int) $data['size_id'],
            'season_id' => isset($data['season_id']) ? (int) $data['season_id'] : null,
            'tyre_type_id' => isset($data['tyre_type_id']) ? (int) $data['tyre_type_id'] : null,
            'fuel_efficiency_id' => isset($data['fuel_efficiency_id']) ? (int) $data['fuel_efficiency_id'] : null,
            'speed_rating_id' => isset($data['speed_rating_id']) ? (int) $data['speed_rating_id'] : null,
            'price' => (float) $data['price'],
            'stock' => (int) $data['stock'],
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'status' => $status,
            'image_url' => $this->uploadImageIfPresent($request),
        ]);

        $tyre->load(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating']);

        return $this->jsonSuccess($this->resource($tyre), 'Tyre created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to update tyres.', null, 403);
        }

        $tyre = Tyre::query()->find($id);
        if (! $tyre) {
            return $this->jsonError('Tyre not found.', null, 404);
        }

        $validator = $this->validator($request, true);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $tyre->brand_id = (int) $data['brand_id'];
        $tyre->model = trim((string) $data['model']);
        $tyre->size_id = (int) $data['size_id'];
        $tyre->season_id = isset($data['season_id']) ? (int) $data['season_id'] : null;
        $tyre->tyre_type_id = isset($data['tyre_type_id']) ? (int) $data['tyre_type_id'] : null;
        $tyre->fuel_efficiency_id = isset($data['fuel_efficiency_id']) ? (int) $data['fuel_efficiency_id'] : null;
        $tyre->speed_rating_id = isset($data['speed_rating_id']) ? (int) $data['speed_rating_id'] : null;
        $tyre->price = (float) $data['price'];
        $tyre->stock = (int) $data['stock'];
        $tyre->description = isset($data['description']) ? trim((string) $data['description']) : null;
        $tyre->status = $this->normalizeStatus($data['status'] ?? true);

        if ($request->hasFile('image')) {
            if ($tyre->image_url && str_contains($tyre->image_url, '/storage/tyre-images/')) {
                Storage::disk('public')->delete('tyre-images/' . basename($tyre->image_url));
            }
            $tyre->image_url = $this->uploadImageIfPresent($request);
        }

        $tyre->save();
        $tyre->load(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating']);

        return $this->jsonSuccess($this->resource($tyre), 'Tyre updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to delete tyres.', null, 403);
        }

        $tyre = Tyre::query()->find($id);
        if (! $tyre) {
            return $this->jsonError('Tyre not found.', null, 404);
        }

        if ($tyre->image_url && str_contains($tyre->image_url, '/storage/tyre-images/')) {
            Storage::disk('public')->delete('tyre-images/' . basename($tyre->image_url));
        }

        $tyre->delete();

        return $this->jsonSuccess(null, 'Tyre deleted successfully.');
    }

    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to bulk update tyres.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $affected = Tyre::query()
            ->whereIn('id', $data['ids'])
            ->update(['status' => $data['status'] === 'active']);

        return $this->jsonSuccess(['updated' => $affected], 'Tyres updated successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to bulk delete tyres.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $ids = $data['ids'];

        DB::transaction(function () use ($ids): void {
            $rows = Tyre::query()->whereIn('id', $ids)->get();
            foreach ($rows as $tyre) {
                if ($tyre->image_url && str_contains($tyre->image_url, '/storage/tyre-images/')) {
                    Storage::disk('public')->delete('tyre-images/' . basename($tyre->image_url));
                }
            }
            Tyre::query()->whereIn('id', $ids)->delete();
        });

        return $this->jsonSuccess(['deleted' => count($ids)], 'Tyres deleted successfully.');
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to download tyre templates.', null, 403);
        }

        $rows = [
            ['brand', 'model', 'size', 'season', 'tyre_type', 'fuel_efficiency', 'speed_rating', 'price', 'stock', 'description', 'status'],
            ['Michelin', 'Pilot Sport 4S', '225/45 R17', 'Summer', 'Performance', 'A', 'Y', '145.00', '24', 'High performance road tyre', 'active'],
        ];

        return $this->downloadSheet($rows, 'tyres-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to export tyres.', null, 403);
        }

        $rows = Tyre::query()
            ->with(['brand', 'size', 'season', 'tyreType', 'fuelEfficiency', 'speedRating'])
            ->orderByDesc('id')
            ->get();

        $data = [['brand', 'model', 'size', 'season', 'tyre_type', 'fuel_efficiency', 'speed_rating', 'price', 'stock', 'description', 'status']];
        foreach ($rows as $row) {
            $data[] = [
                $row->brand?->name ?? '',
                $row->model,
                $row->size?->label ?? '',
                $row->season?->name ?? '',
                $row->tyreType?->name ?? '',
                $row->fuelEfficiency?->rating ?? '',
                $row->speedRating?->rating ?? '',
                number_format((float) $row->price, 2, '.', ''),
                (string) $row->stock,
                $row->description ?? '',
                $row->status ? 'active' : 'inactive',
            ];
        }

        return $this->downloadSheet($data, 'tyres-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to import tyres.', null, 403);
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
        $expected = ['brand', 'model', 'size', 'season', 'tyre_type', 'fuel_efficiency', 'speed_rating', 'price', 'stock', 'description', 'status'];
        if ($header !== $expected) {
            return $this->jsonError('Header must be: '.implode(',', $expected), null, 422);
        }

        $brands = Brand::query()->get()->keyBy(fn (Brand $x) => mb_strtolower($x->name));
        $sizes = Size::query()->get()->keyBy(fn (Size $x) => mb_strtolower($x->label));
        $seasons = Season::query()->get()->keyBy(fn (Season $x) => mb_strtolower($x->name));
        $types = TyreType::query()->get()->keyBy(fn (TyreType $x) => mb_strtolower($x->name));
        $effs = FuelEfficiency::query()->get()->keyBy(fn (FuelEfficiency $x) => mb_strtolower($x->rating));
        $speeds = SpeedRating::query()->get()->keyBy(fn (SpeedRating $x) => mb_strtolower($x->rating));

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $brandKey = mb_strtolower(trim((string) ($row[0] ?? '')));
            $model = trim((string) ($row[1] ?? ''));
            $sizeKey = mb_strtolower(trim((string) ($row[2] ?? '')));
            $seasonKey = mb_strtolower(trim((string) ($row[3] ?? '')));
            $typeKey = mb_strtolower(trim((string) ($row[4] ?? '')));
            $effKey = mb_strtolower(trim((string) ($row[5] ?? '')));
            $speedKey = mb_strtolower(trim((string) ($row[6] ?? '')));
            $price = (float) trim((string) ($row[7] ?? '0'));
            $stock = (int) trim((string) ($row[8] ?? '0'));
            $description = trim((string) ($row[9] ?? ''));
            $statusRaw = mb_strtolower(trim((string) ($row[10] ?? 'active')));

            $brand = $brands->get($brandKey);
            $size = $sizes->get($sizeKey);
            if (! $brand || ! $size || $model === '' || $price <= 0 || $stock < 0) {
                $skipped++;
                continue;
            }

            $status = !in_array($statusRaw, ['inactive', '0', 'false', 'no'], true);
            $season = $seasonKey !== '' ? $seasons->get($seasonKey) : null;
            $type = $typeKey !== '' ? $types->get($typeKey) : null;
            $eff = $effKey !== '' ? $effs->get($effKey) : null;
            $speed = $speedKey !== '' ? $speeds->get($speedKey) : null;

            $existing = Tyre::query()
                ->where('brand_id', $brand->id)
                ->whereRaw('LOWER(model) = ?', [mb_strtolower($model)])
                ->where('size_id', $size->id)
                ->first();

            $payload = [
                'brand_id' => $brand->id,
                'model' => $model,
                'size_id' => $size->id,
                'season_id' => $season?->id,
                'tyre_type_id' => $type?->id,
                'fuel_efficiency_id' => $eff?->id,
                'speed_rating_id' => $speed?->id,
                'price' => $price,
                'stock' => $stock,
                'description' => $description !== '' ? $description : null,
                'status' => $status,
            ];

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                Tyre::query()->create($payload);
                $created++;
            }
        }

        return $this->jsonSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Tyre import completed.');
    }

    public function generateDescription(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('tyres')) {
            return $this->jsonError('You do not have permission to generate tyre descriptions.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'brand' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:255'],
            'season' => ['nullable', 'string', 'max:255'],
            'tyre_type' => ['nullable', 'string', 'max:255'],
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
        $featureToggle = ApiSetting::query()->where('key_name', 'tyre_description_ai_generate')->first();
        if (! $featureToggle?->is_enabled) {
            return $this->jsonError('Tyre description AI is inactive.', null, 422);
        }

        $data = $validator->validated();
        $brand = trim((string) $data['brand']);
        $model = trim((string) $data['model']);
        $size = trim((string) ($data['size'] ?? ''));
        $season = trim((string) ($data['season'] ?? ''));
        $tyreType = trim((string) ($data['tyre_type'] ?? ''));

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a tyre product copywriter. Return strict JSON only.'],
                ['role' => 'user', 'content' => $this->buildDescriptionPrompt($brand, $model, $size, $season, $tyreType)],
            ],
            'temperature' => 0.5,
        ];

        $raw = $this->postJsonWithBearer(self::OPENAI_CHAT_URL, $apiKey, $payload, 30);
        if (! $raw['ok']) {
            return $this->jsonError($raw['error'] ?? 'Failed to generate description via Workatmo AI.', null, 502, [
                'http_code' => $raw['http_code'] ?? 0,
            ]);
        }

        $content = (string) ($raw['body']['choices'][0]['message']['content'] ?? '');
        $decoded = $this->decodeJsonContent($content);
        $description = is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';

        if ($description === '') {
            return $this->jsonError('Workatmo AI returned an unexpected response format.', null, 502);
        }

        return $this->jsonSuccess(['description' => $description], 'Description generated successfully.');
    }

    private function validator(Request $request, bool $isUpdate): \Illuminate\Contracts\Validation\Validator
    {
        $statusRule = ['nullable', Rule::in([true, false, 1, 0, '1', '0', 'active', 'inactive'])];
        $commonRules = [
            'brand_id' => ['required', 'integer', 'min:1', 'exists:brands,id'],
            'model' => ['required', 'string', 'max:255'],
            'size_id' => ['required', 'integer', 'min:1', 'exists:sizes,id'],
            'season_id' => ['nullable', 'integer', 'min:1', 'exists:seasons,id'],
            'tyre_type_id' => ['nullable', 'integer', 'min:1', 'exists:tyre_types,id'],
            'fuel_efficiency_id' => ['nullable', 'integer', 'min:1', 'exists:fuel_efficiencies,id'],
            'speed_rating_id' => ['nullable', 'integer', 'min:1', 'exists:speed_ratings,id'],
            'price' => ['required', 'numeric', 'gt:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:4000'],
            'status' => $statusRule,
            'image' => [$isUpdate ? 'nullable' : 'sometimes', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];

        return Validator::make(array_merge($request->all(), $request->allFiles()), $commonRules);
    }

    private function normalizeStatus(mixed $status): bool
    {
        if (is_string($status)) {
            $normalized = mb_strtolower(trim($status));
            if ($normalized === 'inactive' || $normalized === '0' || $normalized === 'false') {
                return false;
            }
            return true;
        }

        return (bool) $status;
    }

    private function uploadImageIfPresent(Request $request): ?string
    {
        $file = $request->file('image');
        if (! $file) {
            return null;
        }

        // Save directly into public/tyre-images/ so php -S serves it without symlinks
        $dir = public_path('tyre-images');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = uniqid('tyre_', true) . '.' . $file->getClientOriginalExtension();
        $file->move($dir, $filename);

        return '/tyre-images/' . $filename;
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

    private function buildDescriptionPrompt(string $brand, string $model, string $size, string $season, string $tyreType): string
    {
        $parts = [
            "Brand: {$brand}",
            "Model: {$model}",
            $size !== '' ? "Size: {$size}" : null,
            $season !== '' ? "Season: {$season}" : null,
            $tyreType !== '' ? "Tyre Type: {$tyreType}" : null,
        ];
        $spec = implode("\n", array_values(array_filter($parts, fn ($x) => $x !== null)));

        return "Write a concise, sales-ready tyre description in 2-4 sentences using these details:\n{$spec}\n"
            .'Return STRICT JSON only with this exact shape: {"description":"..."} '
            .'No markdown, no extra keys, no commentary.';
    }

    private function resource(Tyre $tyre): array
    {
        return [
            'id' => $tyre->id,
            'brand_id' => $tyre->brand_id,
            'brand_name' => $tyre->brand?->name,
            'model' => $tyre->model,
            'size_id' => $tyre->size_id,
            'size_label' => $tyre->size?->label,
            'season_id' => $tyre->season_id,
            'season_name' => $tyre->season?->name,
            'tyre_type_id' => $tyre->tyre_type_id,
            'tyre_type_name' => $tyre->tyreType?->name,
            'fuel_efficiency_id' => $tyre->fuel_efficiency_id,
            'fuel_efficiency_rating' => $tyre->fuelEfficiency?->rating,
            'speed_rating_id' => $tyre->speed_rating_id,
            'speed_rating' => $tyre->speedRating?->rating,
            'price' => (float) $tyre->price,
            'stock' => (int) $tyre->stock,
            'description' => $tyre->description,
            'status' => (bool) $tyre->status,
            'image_url' => $tyre->image_url,
            'created_at' => $tyre->created_at?->toIso8601String(),
            'updated_at' => $tyre->updated_at?->toIso8601String(),
        ];
    }
}
