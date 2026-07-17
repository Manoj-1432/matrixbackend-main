<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SlotController extends Controller
{
    use ApiResponse;

    /** Valid day values. */
    private const DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: convert "HH:MM" → total minutes (reliable time comparison)
    // ─────────────────────────────────────────────────────────────────────────

    private function toMinutes(string $time): int
    {
        [$h, $m] = explode(':', $time);
        return ((int) $h) * 60 + (int) $m;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admin/slots
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to view slots.', null, 403);
        }

        $slots = Slot::query()
            ->orderByRaw("FIELD(day, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')")
            ->orderBy('start_time')
            ->get()
            ->map(fn (Slot $slot) => $this->resource($slot))
            ->values();

        return $this->jsonSuccess(['slots' => $slots]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/slots
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to create slots.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'day'          => ['required', 'string', Rule::in(self::DAYS)],
            'start_time'   => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time'     => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'max_bookings' => ['nullable', 'integer', 'min:1'],
            'status'       => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        // Normalise to H:i (strip seconds MySQL may return)
        $data['start_time'] = substr($data['start_time'], 0, 5);
        $data['end_time']   = substr($data['end_time'], 0, 5);

        // Reliable time comparison using minutes
        if ($this->toMinutes($data['end_time']) <= $this->toMinutes($data['start_time'])) {
            return $this->jsonError(
                'End time must be after start time.',
                null,
                422,
                ['end_time' => ['End time must be after start time.']]
            );
        }

        // Duplicate check
        $exists = Slot::query()
            ->where('day', $data['day'])
            ->where('start_time', $data['start_time'])
            ->where('end_time', $data['end_time'])
            ->exists();

        if ($exists) {
            return $this->jsonError(
                'A slot with the same day and time already exists.',
                null,
                422,
                ['day' => ['Duplicate slot.']]
            );
        }

        $slot = Slot::query()->create([
            'day'          => $data['day'],
            'start_time'   => $data['start_time'],
            'end_time'     => $data['end_time'],
            'max_bookings' => $data['max_bookings'] ?? 1,
            'status'       => $data['status'] ?? 'active',
        ]);

        return $this->jsonSuccess($this->resource($slot), 'Slot created successfully.', 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/admin/slots/bulk-generate
    // ─────────────────────────────────────────────────────────────────────────

    public function bulkGenerate(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to create slots.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'day'        => ['required', 'string', Rule::in(self::DAYS)],
            'start_time' => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time'   => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'duration'   => ['required', 'integer', 'min:5'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data     = $validator->validated();
        // Normalise to H:i
        $data['start_time'] = substr($data['start_time'], 0, 5);
        $data['end_time']   = substr($data['end_time'], 0, 5);
        $day      = $data['day'];
        $duration = (int) $data['duration'];

        $startMins = $this->toMinutes($data['start_time']);
        $endMins   = $this->toMinutes($data['end_time']);

        if ($endMins <= $startMins) {
            return $this->jsonError(
                'End time must be after start time.',
                null,
                422,
                ['end_time' => ['End time must be after start time.']]
            );
        }

        $created = [];
        $skipped = 0;
        $cursor  = $startMins;

        while ($cursor + $duration <= $endMins) {
            $slotStart = sprintf('%02d:%02d', intdiv($cursor, 60), $cursor % 60);
            $slotEnd   = sprintf('%02d:%02d', intdiv($cursor + $duration, 60), ($cursor + $duration) % 60);

            $exists = Slot::query()
                ->where('day', $day)
                ->where('start_time', $slotStart)
                ->where('end_time', $slotEnd)
                ->exists();

            if (! $exists) {
                $slot = Slot::query()->create([
                    'day'          => $day,
                    'start_time'   => $slotStart,
                    'end_time'     => $slotEnd,
                    'max_bookings' => 1,
                    'status'       => 'active',
                ]);
                $created[] = $this->resource($slot);
            } else {
                $skipped++;
            }

            $cursor += $duration;
        }

        return $this->jsonSuccess(
            ['slots' => $created, 'created' => count($created), 'skipped' => $skipped],
            count($created) . ' slot(s) generated.',
            201
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/admin/slots/{id}
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to update slots.', null, 403);
        }

        $slot = Slot::query()->find($id);
        if (! $slot) {
            return $this->jsonError('Slot not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'day'          => ['required', 'string', Rule::in(self::DAYS)],
            'start_time'   => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'end_time'     => ['required', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'max_bookings' => ['nullable', 'integer', 'min:1'],
            'status'       => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        // Normalise to H:i (strip seconds MySQL may return)
        $data['start_time'] = substr($data['start_time'], 0, 5);
        $data['end_time']   = substr($data['end_time'], 0, 5);

        // Reliable time comparison
        if ($this->toMinutes($data['end_time']) <= $this->toMinutes($data['start_time'])) {
            return $this->jsonError(
                'End time must be after start time.',
                null,
                422,
                ['end_time' => ['End time must be after start time.']]
            );
        }

        // Duplicate check (exclude self)
        $exists = Slot::query()
            ->where('day', $data['day'])
            ->where('start_time', $data['start_time'])
            ->where('end_time', $data['end_time'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return $this->jsonError(
                'A slot with the same day and time already exists.',
                null,
                422,
                ['day' => ['Duplicate slot.']]
            );
        }

        $slot->day          = $data['day'];
        $slot->start_time   = $data['start_time'];
        $slot->end_time     = $data['end_time'];
        $slot->max_bookings = $data['max_bookings'] ?? $slot->max_bookings;
        $slot->status       = $data['status'] ?? $slot->status;
        $slot->save();

        return $this->jsonSuccess($this->resource($slot), 'Slot updated successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PATCH /api/admin/slots/{id}/toggle-status
    // Quick toggle without a full edit payload.
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to update slots.', null, 403);
        }

        $slot = Slot::query()->find($id);
        if (! $slot) {
            return $this->jsonError('Slot not found.', null, 404);
        }

        $slot->status = $slot->status === 'active' ? 'inactive' : 'active';
        $slot->save();

        return $this->jsonSuccess($this->resource($slot), 'Slot status updated.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/admin/slots/{id}
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('slots')) {
            return $this->jsonError('You do not have permission to delete slots.', null, 403);
        }

        $slot = Slot::query()->find($id);
        if (! $slot) {
            return $this->jsonError('Slot not found.', null, 404);
        }

        $slot->delete();

        return $this->jsonSuccess(null, 'Slot deleted successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function resource(Slot $slot): array
    {
        return [
            'id'           => $slot->id,
            'day'          => $slot->day,
            // MySQL time columns return HH:MM:SS — strip seconds so frontend always gets H:i
            'start_time'   => substr((string) $slot->start_time, 0, 5),
            'end_time'     => substr((string) $slot->end_time,   0, 5),
            'max_bookings' => $slot->max_bookings,
            'status'       => $slot->status,
            'created_at'   => $slot->created_at?->toIso8601String(),
            'updated_at'   => $slot->updated_at?->toIso8601String(),
        ];
    }
}
