<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\SpeedRating;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpeedRatingController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to view speed ratings.', null, 403);
        }

        $rows = SpeedRating::query()
            ->orderBy('rating')
            ->get()
            ->map(fn (SpeedRating $row) => $this->resource($row))
            ->values();

        return $this->jsonSuccess(['speed_ratings' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to create speed ratings.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => ['required', 'string', 'max:20'],
            'max_speed' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $rating = mb_strtoupper(trim((string) $data['rating']));
        $exists = SpeedRating::query()
            ->whereRaw('LOWER(rating) = ?', [mb_strtolower($rating)])
            ->exists();
        if ($exists) {
            return $this->jsonError('Duplicate rating is not allowed.', null, 422);
        }

        $row = SpeedRating::query()->create([
            'rating' => $rating,
            'max_speed' => (int) $data['max_speed'],
            'description' => ($data['description'] ?? null) !== null ? trim((string) $data['description']) : null,
            'status' => $data['status'],
        ]);

        return $this->jsonSuccess($this->resource($row), 'Speed rating created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to update speed ratings.', null, 403);
        }

        $row = SpeedRating::query()->find($id);
        if (! $row) {
            return $this->jsonError('Speed rating not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'rating' => ['required', 'string', 'max:20'],
            'max_speed' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $rating = mb_strtoupper(trim((string) $data['rating']));
        $duplicate = SpeedRating::query()
            ->where('id', '!=', $row->id)
            ->whereRaw('LOWER(rating) = ?', [mb_strtolower($rating)])
            ->exists();
        if ($duplicate) {
            return $this->jsonError('Duplicate rating is not allowed.', null, 422);
        }

        $description = ($data['description'] ?? null) !== null ? trim((string) $data['description']) : null;
        $row->rating = $rating;
        $row->max_speed = (int) $data['max_speed'];
        $row->description = $description === '' ? null : $description;
        $row->status = $data['status'];
        $row->save();

        return $this->jsonSuccess($this->resource($row), 'Speed rating updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to delete speed ratings.', null, 403);
        }

        $row = SpeedRating::query()->find($id);
        if (! $row) {
            return $this->jsonError('Speed rating not found.', null, 404);
        }

        $row->delete();

        return $this->jsonSuccess(null, 'Speed rating deleted successfully.');
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to bulk delete speed ratings.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $affected = SpeedRating::query()->whereIn('id', $data['ids'])->delete();

        return $this->jsonSuccess(['deleted' => $affected], 'Speed ratings deleted successfully.');
    }

    public function template(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to download speed rating templates.', null, 403);
        }

        $rows = [
            ['rating', 'max_speed', 'description', 'status'],
            ['H', '210', 'Standard performance', 'active'],
            ['V', '240', 'High performance', 'active'],
            ['W', '270', 'Very high performance', 'active'],
            ['Y', '300', 'Extreme performance', 'active'],
        ];

        return $this->downloadSheet($rows, 'speed-rating-template');
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to export speed ratings.', null, 403);
        }

        $rows = SpeedRating::query()
            ->orderBy('rating')
            ->get(['rating', 'max_speed', 'description', 'status']);

        $data = [['rating', 'max_speed', 'description', 'status']];
        foreach ($rows as $row) {
            $data[] = [
                $row->rating,
                (string) $row->max_speed,
                $row->description ?? '',
                $row->status,
            ];
        }

        return $this->downloadSheet($data, 'speed-ratings-export');
    }

    public function import(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('attributes')) {
            return $this->jsonError('You do not have permission to import speed ratings.', null, 403);
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
        if ($normalizedHeader !== ['rating', 'max_speed', 'description', 'status']) {
            return $this->jsonError('Header must be: rating,max_speed,description,status', null, 422);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (array_slice($rows, 1) as $row) {
            $rating = mb_strtoupper(trim((string) ($row[0] ?? '')));
            $maxSpeed = (int) trim((string) ($row[1] ?? ''));
            $description = trim((string) ($row[2] ?? ''));
            $status = mb_strtolower(trim((string) ($row[3] ?? 'active')));

            if ($rating === '' || $maxSpeed < 1 || ! in_array($status, ['active', 'inactive'], true)) {
                $skipped++;
                continue;
            }

            $existing = SpeedRating::query()
                ->whereRaw('LOWER(rating) = ?', [mb_strtolower($rating)])
                ->first();

            if ($existing) {
                $existing->max_speed = $maxSpeed;
                $existing->description = $description !== '' ? $description : null;
                $existing->status = $status;
                $existing->save();
                $updated++;
            } else {
                SpeedRating::query()->create([
                    'rating' => $rating,
                    'max_speed' => $maxSpeed,
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
        ], 'Speed rating import completed.');
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

    private function resource(SpeedRating $row): array
    {
        return [
            'id' => $row->id,
            'rating' => $row->rating,
            'max_speed' => (int) $row->max_speed,
            'description' => $row->description,
            'status' => $row->status,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}

