<?php

/**
 * Standalone DVLA + tyre demo page (uses API keys from Matrix admin → API Settings).
 * Prefer Super Admin → Test DVLA in the web app. To use this file directly, from
 * `matrixbackend`: `php -S localhost:8888` then open http://localhost:8888/dvla-test.php
 */

declare(strict_types=1);
use App\Services\DvlaTyreLookupService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$registrationNumber = isset($_GET['vrm']) ? (string) $_GET['vrm'] : '';

$responsePayload = null;
$responseCode = null;
$responseOk = null;
$errorMessage = null;
$tyreResult = null;
$tyreError = null;

if ($registrationNumber !== '') {
    /** @var DvlaTyreLookupService $lookup */
    $lookup = $app->make(DvlaTyreLookupService::class);
    $result = $lookup->lookupByVrm($registrationNumber);

    $responseOk = $result['dvla_success'];
    $responseCode = $result['dvla_http_code'];
    $errorMessage = $result['dvla_error'];
    $responsePayload = $result['vehicle'];
    $tyreResult = $result['tyre'];
    $tyreError = $result['tyre_error'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DVLA Tyre Lookup</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 980px; margin: 30px auto; padding: 0 16px; background: #f8fafc; color: #0f172a; }
        h1 { margin-bottom: 16px; }
        h2 { margin: 18px 0 8px; font-size: 20px; }
        form { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        input { padding: 10px; width: 260px; font-size: 16px; border: 1px solid #cbd5e1; border-radius: 8px; }
        .vrm { text-transform: uppercase; }
        button { padding: 10px 14px; font-size: 15px; cursor: pointer; border: 0; border-radius: 8px; background: #0f172a; color: #fff; }
        .ok { color: #166534; font-weight: 700; }
        .fail { color: #b42318; font-weight: 700; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #e2e8f0; padding: 10px; text-align: left; font-size: 14px; vertical-align: top; }
        th { background: #f1f5f9; width: 35%; }
        ul { margin: 8px 0 0 20px; }
        .muted { color: #64748b; font-size: 13px; }
    </style>
</head>
<body>
    <h1>DVLA Vehicle + Tyre Lookup</h1>
    <p class="muted">Keys are read from Laravel <strong>api_settings</strong> (DVLA + Workatmo Tyre API). Use Matrix Admin → Test DVLA for the integrated tool.</p>
    <form method="GET">
        <input class="vrm" type="text" name="vrm" placeholder="Enter registrationNumber" value="<?= htmlspecialchars($registrationNumber) ?>" required>
        <button type="submit">Check</button>
    </form>

    <?php if ($registrationNumber !== '') { ?>
        <p class="<?= $responseOk ? 'ok' : 'fail' ?> card">
            <?= $responseOk ? 'Success' : 'Failed' ?> - HTTP <?= (int) $responseCode ?>
        </p>
        <?php if ($errorMessage) { ?>
            <p class="fail"><?= htmlspecialchars($errorMessage) ?></p>
        <?php } ?>

        <?php if (is_array($responsePayload)) { ?>
            <div class="card">
                <h2>Vehicle Details</h2>
                <table>
                    <tr><th>Registration</th><td><?= htmlspecialchars((string) ($responsePayload['registrationNumber'] ?? $registrationNumber)) ?></td></tr>
                    <tr><th>Make</th><td><?= htmlspecialchars((string) ($responsePayload['make'] ?? 'N/A')) ?></td></tr>
                    <tr><th>Model</th><td><?= htmlspecialchars((string) ($responsePayload['model'] ?? $responsePayload['vehicleModel'] ?? $responsePayload['modelVariant'] ?? $responsePayload['variant'] ?? $responsePayload['derivative'] ?? 'Not provided by DVLA')) ?></td></tr>
                    <tr><th>Year</th><td><?= htmlspecialchars((string) ($responsePayload['yearOfManufacture'] ?? 'N/A')) ?></td></tr>
                    <tr><th>Fuel Type</th><td><?= htmlspecialchars((string) ($responsePayload['fuelType'] ?? 'N/A')) ?></td></tr>
                    <tr><th>Colour</th><td><?= htmlspecialchars((string) ($responsePayload['colour'] ?? 'N/A')) ?></td></tr>
                    <tr><th>MOT Status</th><td><?= htmlspecialchars((string) ($responsePayload['motStatus'] ?? 'N/A')) ?></td></tr>
                    <tr><th>MOT Expiry</th><td><?= htmlspecialchars((string) ($responsePayload['motExpiryDate'] ?? 'N/A')) ?></td></tr>
                    <tr><th>Tax Status</th><td><?= htmlspecialchars((string) ($responsePayload['taxStatus'] ?? 'N/A')) ?></td></tr>
                </table>
            </div>
        <?php } ?>

        <div class="card">
            <h2>Tyre Recommendation</h2>
            <?php if (is_array($tyreResult)) { ?>
                <table>
                    <tr>
                        <th>Likely Tyre Sizes</th>
                        <td>
                            <?php if (! empty($tyreResult['likely_sizes']) && is_array($tyreResult['likely_sizes'])) { ?>
                                <ul>
                                    <?php foreach ($tyreResult['likely_sizes'] as $size) { ?>
                                        <li><?= htmlspecialchars((string) $size) ?></li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                N/A
                            <?php } ?>
                        </td>
                    </tr>
                    <tr><th>Front Pressure (PSI)</th><td><?= htmlspecialchars((string) ($tyreResult['recommended_pressure_psi_front'] ?? 'N/A')) ?></td></tr>
                    <tr><th>Rear Pressure (PSI)</th><td><?= htmlspecialchars((string) ($tyreResult['recommended_pressure_psi_rear'] ?? 'N/A')) ?></td></tr>
                    <tr>
                        <th>Notes</th>
                        <td>
                            <?php if (! empty($tyreResult['notes']) && is_array($tyreResult['notes'])) { ?>
                                <ul>
                                    <?php foreach ($tyreResult['notes'] as $note) { ?>
                                        <li><?= htmlspecialchars((string) $note) ?></li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                N/A
                            <?php } ?>
                        </td>
                    </tr>
                </table>
            <?php } else { ?>
                <p class="fail">Tyre recommendation unavailable.</p>
                <?php if (is_array($tyreError)) { ?>
                    <p class="muted"><?= htmlspecialchars((string) ($tyreError['error'] ?? 'Unknown error')) ?></p>
                <?php } ?>
            <?php } ?>
        </div>
    <?php } ?>
</body>
</html>
