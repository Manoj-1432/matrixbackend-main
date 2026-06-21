<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\FuelEfficiency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FuelEfficiencyController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view fuel efficiency ratings.', null, 403);
        }

        $rows = FuelEfficiency::query()
            ->orderBy('rating')
            ->get()
            ->map(fn (FuelEfficiency $row) => $this->resource($row))
            ->values();

        return $this->jsonSuccess(['fuel_efficiencies' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to create fuel efficiency ratings.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $rating = mb_strtoupper(trim((string) $data['rating']));
        $exists = FuelEfficiency::query()->where('rating', $rating)->exists();
        if ($exists) {
            return $this->jsonError('Duplicate rating is not allowed.', null, 422);
        }

        $row = FuelEfficiency::query()->create([
            'rating' => $rating,
            'description' => ($data['description'] ?? null) !== null ? trim((string) $data['description']) : null,
            'status' => $data['status'],
        ]);

        return $this->jsonSuccess($this->resource($row), 'Fuel efficiency rating created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to update fuel efficiency ratings.', null, 403);
        }

        $row = FuelEfficiency::query()->find($id);
        if (! $row) {
            return $this->jsonError('Fuel efficiency rating not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'rating' => ['required', Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $rating = mb_strtoupper(trim((string) $data['rating']));
        $duplicate = FuelEfficiency::query()
            ->where('id', '!=', $row->id)
            ->where('rating', $rating)
            ->exists();
        if ($duplicate) {
            return $this->jsonError('Duplicate rating is not allowed.', null, 422);
        }

        $description = ($data['description'] ?? null) !== null ? trim((string) $data['description']) : null;
        $row->rating = $rating;
        $row->description = $description === '' ? null : $description;
        $row->status = $data['status'];
        $row->save();

        return $this->jsonSuccess($this->resource($row), 'Fuel efficiency rating updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to delete fuel efficiency ratings.', null, 403);
        }

        $row = FuelEfficiency::query()->find($id);
        if (! $row) {
            return $this->jsonError('Fuel efficiency rating not found.', null, 404);
        }

        $row->delete();

        return $this->jsonSuccess(null, 'Fuel efficiency rating deleted successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk delete fuel efficiency ratings.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $affected = FuelEfficiency::query()->whereIn('id', $data['ids'])->delete();

        return $this->jsonSuccess(['deleted' => $affected], 'Fuel efficiency ratings deleted successfully.');
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to download fuel efficiency templates.', null, 403);
        }

        $rows = [
            ['rating', 'description', 'status'],
            ['A', 'Best fuel efficiency', 'active'],
            ['B', 'Good efficiency', 'active'],
            ['C', 'Average efficiency', 'active'],
            ['D', 'Below average efficiency', 'inactive'],
            ['E', 'Lowest fuel efficiency', 'inactive'],
        ];

        return $this->downloadSheet($rows, 'fuel-efficiency-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to export fuel efficiency ratings.', null, 403);
        }

        $rows = FuelEfficiency::query()
            ->orderBy('rating')
            ->get(['rating', 'description', 'status']);
        $data = [['rating', 'description', 'status']];
        foreach ($rows as $row) {
            $data[] = [
                $row->rating,
                $row->description ?? '',
                $row->status,
            ];
        }

        return $this->downloadSheet($data, 'fuel-efficiency-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to import fuel efficiency ratings.', null, 403);
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
        if ($normalizedHeader !== ['rating', 'description', 'status']) {
            return $this->jsonError('Header must be: rating,description,status', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $rating = mb_strtoupper(trim((string) ($row[0] ?? '')));
            $description = trim((string) ($row[1] ?? ''));
            $status = mb_strtolower(trim((string) ($row[2] ?? 'active')));

            if (! in_array($rating, ['A', 'B', 'C', 'D', 'E'], true) || ! in_array($status, ['active', 'inactive'], true)) {
                $skipped++;
                continue;
            }

            $existing = FuelEfficiency::query()
                ->where('rating', $rating)
                ->first();

            if ($existing) {
                $existing->description = $description !== '' ? $description : null;
                $existing->status = $status;
                $existing->save();
                $updated++;
            } else {
                FuelEfficiency::query()->create([
                    'rating' => $rating,
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
        ], 'Fuel efficiency import completed.');
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

    private function resource(FuelEfficiency $row): array
    {
        return [
            'id' => $row->id,
            'rating' => $row->rating,
            'description' => $row->description,
            'status' => $row->status,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}

