<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SeasonController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view seasons.', null, 403);
        }

        $seasons = Season::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Season $season) => $this->resource($season))
            ->values();

        return $this->jsonSuccess(['seasons' => $seasons]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to create seasons.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $exists = Season::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->exists();
        if ($exists) {
            return $this->jsonError('Duplicate season name is not allowed.', null, 422);
        }

        $season = Season::query()->create([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
        ]);

        return $this->jsonSuccess($this->resource($season), 'Season created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to update seasons.', null, 403);
        }

        $season = Season::query()->find($id);
        if (! $season) {
            return $this->jsonError('Season not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $duplicate = Season::query()
            ->where('id', '!=', $season->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
            ->exists();
        if ($duplicate) {
            return $this->jsonError('Duplicate season name is not allowed.', null, 422);
        }

        $season->name = trim($data['name']);
        $season->description = $data['description'] ?? null;
        $season->status = $data['status'];
        $season->save();

        return $this->jsonSuccess($this->resource($season), 'Season updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to delete seasons.', null, 403);
        }

        $season = Season::query()->find($id);
        if (! $season) {
            return $this->jsonError('Season not found.', null, 404);
        }

        $season->delete();

        return $this->jsonSuccess(null, 'Season deleted successfully.');
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk update seasons.', null, 403);
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
        $affected = Season::query()
            ->whereIn('id', $data['ids'])
            ->update(['status' => $data['status']]);

        return $this->jsonSuccess(['updated' => $affected], 'Seasons updated successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk delete seasons.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $affected = Season::query()->whereIn('id', $data['ids'])->delete();

        return $this->jsonSuccess(['deleted' => $affected], 'Seasons deleted successfully.');
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to download season templates.', null, 403);
        }

        $rows = [
            ['name', 'description', 'status'],
            ['Summer', 'Best for warm weather', 'active'],
            ['Winter', 'Best for cold weather', 'active'],
            ['All Season', 'Balanced performance all year', 'active'],
        ];

        return $this->downloadSheet($rows, 'season-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to export seasons.', null, 403);
        }

        $rows = Season::query()
            ->orderBy('name')
            ->get(['name', 'description', 'status']);
        $data = [['name', 'description', 'status']];
        foreach ($rows as $row) {
            $data[] = [
                $row->name,
                $row->description ?? '',
                $row->status,
            ];
        }

        return $this->downloadSheet($data, 'seasons-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to import seasons.', null, 403);
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
        if ($normalizedHeader !== ['name', 'description', 'status']) {
            return $this->jsonError('Header must be: name,description,status', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $name = trim((string) ($row[0] ?? ''));
            $description = trim((string) ($row[1] ?? ''));
            $status = mb_strtolower(trim((string) ($row[2] ?? 'active')));

            if ($name === '') {
                $skipped++;
                continue;
            }
            if (! in_array($status, ['active', 'inactive'], true)) {
                $skipped++;
                continue;
            }

            $existing = Season::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing) {
                $existing->description = $description !== '' ? $description : null;
                $existing->status = $status;
                $existing->save();
                $updated++;
            } else {
                Season::query()->create([
                    'name' => $name,
                    'description' => $description !== '' ? $description : null,
                    'status' => $status,
                ]);
                $created++;
            }
        }

        return $this->jsonSuccess([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Season import completed.');
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

    private function resource(Season $season): array
    {
        return [
            'id' => $season->id,
            'name' => $season->name,
            'description' => $season->description,
            'status' => $season->status,
            'created_at' => $season->created_at?->toIso8601String(),
            'updated_at' => $season->updated_at?->toIso8601String(),
        ];
    }
}

