<?php

namespace App\Http\Controllers\Admin;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class SystemUpdateController extends Controller
{
    use ApiResponse;

    /**
     * GET /admin/system-update — pending migrations and readiness (read-only).
     */
    public function status(Request $request, Migrator $migrator): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('update')) {
            return $this->jsonError('You do not have permission to view system update status.', null, 403);
        }

        $path = database_path('migrations');
        $files = $migrator->getMigrationFiles([$path]);

        if (! $migrator->repositoryExists()) {
            return $this->jsonSuccess([
                'repository_exists' => false,
                'pending_migrations' => array_keys($files),
                'pending_count' => count($files),
                'note' => 'Migration history table is missing; the next run will create it and apply migrations.',
            ]);
        }

        $ran = $migrator->getRepository()->getRan();
        $pending = array_values(array_diff(array_keys($files), $ran));

        return $this->jsonSuccess([
            'repository_exists' => true,
            'pending_migrations' => $pending,
            'pending_count' => count($pending),
        ]);
    }

    /**
     * POST /admin/system-update — run pending migrations, then clear caches.
     *
     * Uses `migrate` (not migrate:fresh / refresh) so existing rows are preserved.
     */
    public function run(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor || ! $actor->hasPermission('update')) {
            return $this->jsonError('You do not have permission to run system updates.', null, 403);
        }

        try {
            $migrateExit = Artisan::call('migrate', ['--force' => true]);
            $migrateOutput = trim(Artisan::output());

            if ($migrateExit !== 0) {
                return $this->jsonError(
                    'Database migration did not complete successfully.',
                    ['migrate_output' => $migrateOutput],
                    500
                );
            }

            $clearExit = Artisan::call('optimize:clear');
            $cacheOutput = trim(Artisan::output());

            if ($clearExit !== 0) {
                return $this->jsonError(
                    'Migrations ran, but clearing application caches failed.',
                    [
                        'migrate_output' => $migrateOutput,
                        'cache_output' => $cacheOutput,
                    ],
                    500
                );
            }

            return $this->jsonSuccess([
                'migrate_output' => $migrateOutput !== '' ? $migrateOutput : 'No new migrations to run.',
                'cache_output' => $cacheOutput !== '' ? $cacheOutput : 'Caches cleared.',
            ], 'Update completed successfully.');
        } catch (Throwable $e) {
            report($e);

            return $this->jsonError(
                'Update failed: '.$e->getMessage(),
                null,
                500
            );
        }
    }
}
