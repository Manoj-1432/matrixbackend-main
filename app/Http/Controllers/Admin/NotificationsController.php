<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationsController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('notifications')) {
            return $this->jsonError('You do not have permission to view notifications.', null, 403);
        }

        $rows = Notification::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (Notification $row) => $this->resource($row))
            ->values();

        return $this->jsonSuccess(['notifications' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('notifications')) {
            return $this->jsonError('You do not have permission to create notifications.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'link' => ['nullable', 'url', 'max:2048'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $row = Notification::query()->create([
            'title' => trim((string) $data['title']),
            'color' => trim((string) $data['color']),
            'link' => ($data['link'] ?? null) !== null ? trim((string) $data['link']) : null,
        ]);

        return $this->jsonSuccess($this->resource($row), 'Notification created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('notifications')) {
            return $this->jsonError('You do not have permission to update notifications.', null, 403);
        }

        $row = Notification::query()->find($id);
        if (! $row) {
            return $this->jsonError('Notification not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'link' => ['nullable', 'url', 'max:2048'],
        ]);
        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        $row->title = trim((string) $data['title']);
        $row->color = trim((string) $data['color']);
        $link = ($data['link'] ?? null) !== null ? trim((string) $data['link']) : null;
        $row->link = $link === '' ? null : $link;
        $row->save();

        return $this->jsonSuccess($this->resource($row), 'Notification updated successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('notifications')) {
            return $this->jsonError('You do not have permission to delete notifications.', null, 403);
        }

        $row = Notification::query()->find($id);
        if (! $row) {
            return $this->jsonError('Notification not found.', null, 404);
        }

        $row->delete();

        return $this->jsonSuccess(null, 'Notification deleted successfully.');
    }

    private function resource(Notification $row): array
    {
        return [
            'id' => $row->id,
            'title' => $row->title,
            'color' => $row->color,
            'link' => $row->link,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
