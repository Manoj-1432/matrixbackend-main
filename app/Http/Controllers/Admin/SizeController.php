<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ApiSetting;
use App\Models\Size;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SizeController extends Controller
{
    use ApiResponse;

    private const OPENAI_CHAT_URL = 'https://api.openai.com/v1/chat/completions';

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view sizes.', null, 403);
        }

        $sizes = Size::query()
            ->orderBy('width')
            ->orderBy('profile')
            ->orderBy('rim')
            ->get()
            ->map(fn (Size $size) => $this->resource($size))
            ->values();

        return $this->jsonSuccess(['sizes' => $sizes]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to create sizes.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'width' => ['required', 'integer', 'min:1'],
            'profile' => ['required', 'integer', 'min:1'],
            'rim' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $label = $this->buildLabel((int) $data['width'], (int) $data['profile'], (int) $data['rim']);

        $exists = Size::query()
            ->where('width', (int) $data['width'])
            ->where('profile', (int) $data['profile'])
            ->where('rim', (int) $data['rim'])
            ->exists();
        if ($exists) {
            return $this->jsonError('Duplicate size is not allowed.', null, 422);
        }

        $size = Size::query()->create([
            'width' => (int) $data['width'],
            'profile' => (int) $data['profile'],
            'rim' => (int) $data['rim'],
            'label' => $label,
        ]);

        return $this->jsonSuccess($this->resource($size), 'Size created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to update sizes.', null, 403);
        }

        $size = Size::query()->find($id);
        if (! $size) {
            return $this->jsonError('Size not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'width' => ['required', 'integer', 'min:1'],
            'profile' => ['required', 'integer', 'min:1'],
            'rim' => ['required', 'integer', 'min:1'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $label = $this->buildLabel((int) $data['width'], (int) $data['profile'], (int) $data['rim']);

        $duplicate = Size::query()
            ->where('id', '!=', $size->id)
            ->where('width', (int) $data['width'])
            ->where('profile', (int) $data['profile'])
            ->where('rim', (int) $data['rim'])
            ->exists();
        if ($duplicate) {
            return $this->jsonError('Duplicate size is not allowed.', null, 422);
        }

        $size->width = (int) $data['width'];
        $size->profile = (int) $data['profile'];
        $size->rim = (int) $data['rim'];
        $size->label = $label;
        $size->save();

        return $this->jsonSuccess($this->resource($size), 'Size updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to delete sizes.', null, 403);
        }

        $size = Size::query()->find($id);
        if (! $size) {
            return $this->jsonError('Size not found.', null, 404);
        }

        $hasTyres = DB::table('tyres')->where('size_id', $size->id)->exists();
        if ($hasTyres) {
            return $this->jsonError('Cannot delete size because it is used by one or more tyres.', null, 409);
        }

        $size->delete();

        return $this->jsonSuccess(null, 'Size deleted successfully.');
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk update sizes.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:1'],
            'profile' => ['nullable', 'integer', 'min:1'],
            'rim' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $hasAnyField = array_key_exists('width', $data) || array_key_exists('profile', $data) || array_key_exists('rim', $data);
        if (! $hasAnyField) {
            return $this->jsonError('Provide at least one field to update (width/profile/rim).', null, 422);
        }

        $sizes = Size::query()->whereIn('id', $data['ids'])->get()->keyBy('id');
        if ($sizes->isEmpty()) {
            return $this->jsonError('No matching sizes found.', null, 404);
        }

        $targets = [];
        foreach ($sizes as $size) {
            $newWidth = array_key_exists('width', $data) ? (int) $data['width'] : (int) $size->width;
            $newProfile = array_key_exists('profile', $data) ? (int) $data['profile'] : (int) $size->profile;
            $newRim = array_key_exists('rim', $data) ? (int) $data['rim'] : (int) $size->rim;
            $targets[$size->id] = [$newWidth, $newProfile, $newRim];
        }

        $targetKeys = array_map(fn ($t) => "{$t[0]}|{$t[1]}|{$t[2]}", $targets);
        if (count($targetKeys) !== count(array_unique($targetKeys))) {
            return $this->jsonError('Bulk edit would create duplicate sizes within selection.', null, 422);
        }

        foreach ($targets as [$w, $p, $r]) {
            $existsOutside = Size::query()
                ->whereNotIn('id', array_keys($targets))
                ->where('width', $w)
                ->where('profile', $p)
                ->where('rim', $r)
                ->exists();
            if ($existsOutside) {
                return $this->jsonError('Bulk edit would create duplicate size.', null, 422);
            }
        }

        DB::transaction(function () use ($targets): void {
            foreach ($targets as $id => [$w, $p, $r]) {
                $size = Size::query()->find($id);
                if (! $size) {
                    continue;
                }
                $size->width = $w;
                $size->profile = $p;
                $size->rim = $r;
                $size->label = $this->buildLabel($w, $p, $r);
                $size->save();
            }
        });

        return $this->jsonSuccess(['updated' => count($targets)], 'Sizes updated successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk delete sizes.', null, 403);
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
        $tyreCountsBySize = DB::table('tyres')
            ->select('size_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('size_id', $ids)
            ->groupBy('size_id')
            ->pluck('aggregate', 'size_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        $blockedIds = array_values(array_map(
            fn ($sizeId) => (int) $sizeId,
            array_keys(array_filter($tyreCountsBySize, fn (int $count) => $count > 0))
        ));
        $deletableIds = array_values(array_diff($ids, $blockedIds));
        $affected = 0;

        if (count($deletableIds) > 0) {
            $affected = Size::query()->whereIn('id', $deletableIds)->delete();
        }

        $blockedCount = count($blockedIds);
        $message = $blockedCount > 0
            ? "Deleted {$affected} size(s). Skipped {$blockedCount} size(s) because they are used by tyres."
            : 'Sizes deleted successfully.';

        return $this->jsonSuccess([
            'deleted' => $affected,
            'skipped' => $blockedCount,
            'blocked_ids' => $blockedIds,
        ], $message);
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to download size templates.', null, 403);
        }

        $rows = [
            ['width', 'profile', 'rim'],
            ['205', '55', '16'],
            ['225', '45', '17'],
            ['235', '40', '18'],
        ];

        return $this->downloadSheet($rows, 'size-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to export sizes.', null, 403);
        }

        $rows = Size::query()
            ->orderBy('width')
            ->orderBy('profile')
            ->orderBy('rim')
            ->get(['width', 'profile', 'rim']);

        $data = [['width', 'profile', 'rim']];
        foreach ($rows as $row) {
            $data[] = [
                (string) $row->width,
                (string) $row->profile,
                (string) $row->rim,
            ];
        }

        return $this->downloadSheet($data, 'sizes-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to import sizes.', null, 403);
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
        if ($normalizedHeader !== ['width', 'profile', 'rim']) {
            return $this->jsonError('Header must be: width,profile,rim', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $width = (int) trim((string) ($row[0] ?? ''));
            $profile = (int) trim((string) ($row[1] ?? ''));
            $rim = (int) trim((string) ($row[2] ?? ''));

            if ($width < 1 || $profile < 1 || $rim < 1) {
                $skipped++;
                continue;
            }

            $label = $this->buildLabel($width, $profile, $rim);
            $existing = Size::query()
                ->where('width', $width)
                ->where('profile', $profile)
                ->where('rim', $rim)
                ->first();

            if ($existing) {
                $existing->label = $label;
                $existing->save();
                $updated++;
            } else {
                Size::query()->create([
                    'width' => $width,
                    'profile' => $profile,
                    'rim' => $rim,
                    'label' => $label,
                ]);
                $created++;
            }
        }

        return $this->jsonSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Size import completed.');
    }

    public function generateWithAi(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to generate sizes.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'country' => ['required', 'string', 'max:60'],
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'vehicle_type' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $country = trim((string) $data['country']);
        $count = (int) $data['count'];
        $vehicleType = trim((string) ($data['vehicle_type'] ?? ''));

        if (! in_array($country, ['UK', 'Europe', 'Global'], true)) {
            return $this->jsonError('Country must be one of: UK, Europe, Global.', null, 422);
        }
        if ($vehicleType !== '' && ! in_array($vehicleType, ['car', 'suv', 'van'], true)) {
            return $this->jsonError('Vehicle type must be one of: car, suv, van.', null, 422);
        }

        $setting = ApiSetting::query()->where('key_name', 'openai')->first();
        $apiKey = trim((string) ($setting?->value ?? ''));
        if ($apiKey === '') {
            return $this->jsonError('Workatmo AI is inactive.', null, 422);
        }
        if (! $setting?->is_enabled) {
            return $this->jsonError('Workatmo AI is inactive.', null, 422);
        }

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a tyre catalog assistant. Return strict JSON only.'],
                ['role' => 'user', 'content' => $this->buildGenerateSizesPrompt($country, $count, $vehicleType)],
            ],
            'temperature' => 0.3,
        ];

        $aiResult = $this->postJsonWithBearer(self::OPENAI_CHAT_URL, $apiKey, $payload, 30);
        if (! $aiResult['ok']) {
            return $this->jsonError($aiResult['error'] ?? 'Failed to generate sizes via Workatmo AI.', null, 502, [
                'http_code' => $aiResult['http_code'] ?? 0,
            ]);
        }

        $content = (string) ($aiResult['body']['choices'][0]['message']['content'] ?? '');
        $decoded = $this->decodeJsonContent($content);
        if (! is_array($decoded) || ! isset($decoded['sizes']) || ! is_array($decoded['sizes'])) {
            return $this->jsonError('Workatmo AI returned an unexpected response format.', null, 502);
        }

        $sizes = [];
        foreach ($decoded['sizes'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $width = isset($row['width']) ? (int) $row['width'] : 0;
            $profile = isset($row['profile']) ? (int) $row['profile'] : 0;
            $rim = isset($row['rim']) ? (int) $row['rim'] : 0;
            if ($width < 1 || $profile < 1 || $rim < 1) {
                continue;
            }

            $sizes[] = [
                'width' => $width,
                'profile' => $profile,
                'rim' => $rim,
                'label' => $this->buildLabel($width, $profile, $rim),
            ];
        }

        $unique = [];
        $seen = [];
        foreach ($sizes as $item) {
            $key = $item['width'].'|'.$item['profile'].'|'.$item['rim'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $item;
        }

        if (count($unique) === 0) {
            return $this->jsonError('No valid sizes were generated. Please try again.', null, 502);
        }

        return $this->jsonSuccess([
            'country' => $country,
            'count' => $count,
            'vehicle_type' => $vehicleType !== '' ? $vehicleType : null,
            'sizes' => array_slice($unique, 0, $count),
        ], 'Sizes generated successfully.');
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

    private function buildLabel(int $width, int $profile, int $rim): string
    {
        return sprintf('%d/%d R%d', $width, $profile, $rim);
    }

    private function buildGenerateSizesPrompt(string $country, int $count, string $vehicleType): string
    {
        $vehicleLine = $vehicleType !== '' ? "Vehicle type: {$vehicleType}. " : '';

        return "Generate {$count} realistic tyre sizes for {$country}. "
            .$vehicleLine
            .'Return STRICT JSON only with this exact shape: '
            .'{"sizes":[{"width":205,"profile":55,"rim":16}]}. '
            .'Rules: integers only, no duplicates, no markdown, no explanation.';
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

    private function resource(Size $size): array
    {
        return [
            'id' => $size->id,
            'width' => (int) $size->width,
            'profile' => (int) $size->profile,
            'rim' => (int) $size->rim,
            'label' => $size->label,
            'created_at' => $size->created_at?->toIso8601String(),
            'updated_at' => $size->updated_at?->toIso8601String(),
        ];
    }
}

