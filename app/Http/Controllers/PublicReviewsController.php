<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ApiResponse;
use App\Models\ApiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PublicReviewsController extends Controller
{
    use ApiResponse;

    private const PLACE_ID    = 'ChIJlXLWRoslv6gROprctmA7iOs';
    private const CACHE_KEY   = 'google_place_reviews';
    private const CACHE_TTL   = 21600; // 6 hours

    public function index(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->fetchFromGoogle();
        });

        return $this->jsonSuccess($data);
    }

    private function fetchFromGoogle(): array
    {
        $apiKey = ApiSetting::query()
            ->where('key_name', 'google_maps')
            ->value('value');

        if (empty($apiKey)) {
            return $this->fallback();
        }

        try {
            $res = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => self::PLACE_ID,
                'fields'   => 'name,rating,user_ratings_total,reviews',
                'key'      => $apiKey,
                'language' => 'en',
            ]);

            if (! $res->successful()) {
                return $this->fallback();
            }

            $body   = $res->json();
            $result = $body['result'] ?? [];

            if (empty($result)) {
                return $this->fallback();
            }

            $reviews = collect($result['reviews'] ?? [])
                ->filter(fn ($r) => ! empty($r['text']))
                ->sortByDesc('rating')
                ->values()
                ->map(fn ($r) => [
                    'author'     => $r['author_name'] ?? 'Google Reviewer',
                    'rating'     => (int) ($r['rating'] ?? 5),
                    'text'       => $r['text'],
                    'time'       => $r['relative_time_description'] ?? '',
                    'photo_url'  => $r['profile_photo_url'] ?? null,
                ])
                ->toArray();

            return [
                'place_name'       => $result['name'] ?? 'Matrix Mobile Tyres',
                'rating'           => round((float) ($result['rating'] ?? 5), 1),
                'total_ratings'    => (int) ($result['user_ratings_total'] ?? 0),
                'reviews'          => $reviews,
                'place_url'        => 'https://search.google.com/local/reviews?placeid=' . self::PLACE_ID,
            ];
        } catch (\Throwable) {
            return $this->fallback();
        }
    }

    private function fallback(): array
    {
        return [
            'place_name'    => 'Matrix Mobile Tyres',
            'rating'        => 5.0,
            'total_ratings' => 0,
            'reviews'       => [],
            'place_url'     => 'https://search.google.com/local/reviews?placeid=' . self::PLACE_ID,
        ];
    }
}
