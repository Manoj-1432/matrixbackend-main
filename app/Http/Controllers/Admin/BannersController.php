<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BannersController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('banners')) {
            return $this->jsonError('You do not have permission to view banners.', null, 403);
        }

        $rows = Banner::query()
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Banner $row) => $this->resource($row))
            ->values();

        return $this->jsonSuccess(['banners' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('banners')) {
            return $this->jsonError('You do not have permission to create banners.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'title'      => ['required', 'string', 'max:255'],
            'image_url'  => ['nullable', 'string', 'max:2048'],
            'link'       => ['nullable', 'url', 'max:2048'],
            'is_active'  => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $row = Banner::query()->create([
            'title'      => trim((string) $data['title']),
            'image_url'  => isset($data['image_url']) ? trim((string) $data['image_url']) : null,
            'link'       => isset($data['link']) ? trim((string) $data['link']) : null,
            'is_active'  => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return $this->jsonSuccess($this->resource($row), 'Banner created successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('banners')) {
            return $this->jsonError('You do not have permission to update banners.', null, 403);
        }

        $row = Banner::query()->find($id);
        if (! $row) {
            return $this->jsonError('Banner not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'title'      => ['required', 'string', 'max:255'],
            'image_url'  => ['nullable', 'string', 'max:2048'],
            'link'       => ['nullable', 'url', 'max:2048'],
            'is_active'  => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError('Validation failed.', null, 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        $row->title      = trim((string) $data['title']);
        $row->image_url  = isset($data['image_url']) && $data['image_url'] !== '' ? trim((string) $data['image_url']) : null;
        $row->link       = isset($data['link']) && $data['link'] !== '' ? trim((string) $data['link']) : null;
        $row->is_active  = (bool) ($data['is_active'] ?? $row->is_active);
        $row->sort_order = (int) ($data['sort_order'] ?? $row->sort_order);
        $row->save();

        return $this->jsonSuccess($this->resource($row), 'Banner updated successfully.');
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('banners')) {
            return $this->jsonError('You do not have permission to upload banner images.', null, 403);
        }

        $validator = Validator::make($request->allFiles(), [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return $this->jsonError(
                'Invalid file. Allowed: JPG, JPEG, PNG, WEBP, GIF. Max size: 5MB.',
                null,
                422,
                $validator->errors()->toArray()
            );
        }

        $file = $request->file('image');
        $path = $file->store('banners', 'public');
        $url  = Storage::disk('public')->url($path);

        return $this->jsonSuccess(['url' => $url], 'Banner image uploaded successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermission('banners')) {
            return $this->jsonError('You do not have permission to delete banners.', null, 403);
        }

        $row = Banner::query()->find($id);
        if (! $row) {
            return $this->jsonError('Banner not found.', null, 404);
        }

        // Clean up uploaded image if stored locally
        if ($row->image_url && str_contains((string) $row->image_url, '/storage/banners/')) {
            $relativePath = 'banners/' . basename((string) $row->image_url);
            Storage::disk('public')->delete($relativePath);
        }

        $row->delete();

        return $this->jsonSuccess(null, 'Banner deleted successfully.');
    }

    private function resource(Banner $row): array
    {
        return [
            'id'         => $row->id,
            'title'      => $row->title,
            'image_url'  => $row->image_url,
            'link'       => $row->link,
            'is_active'  => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
