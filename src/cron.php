<?php

use Dotenv\Dotenv;
use Pingdom\Client;
use Damianopetrungaro\CachetSDK\CachetClient;
use Damianopetrungaro\CachetSDK\Points\PointFactory;
use Damianopetrungaro\CachetSDK\Components\ComponentFactory;

// Require our helpers
require __DIR__ . '/helpers.php';

// Check if composer dependencies are installed
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    write('Error: Run `composer install` before running this script.');
    exit(1);
}

// Require the composer autoloader file
require __DIR__ . '/../vendor/autoload.php';


// Load the configuration file
$dotenv = new Dotenv(__DIR__ . '/../');
$dotenv->load();
$dotenv->required([
    'PINGDOM_API_KEY',
    'CACHET_HOST',
    'CACHET_API_KEY',
])->notEmpty();


// Initialize the API clients
$cachetClient  = new CachetClient(getenv('CACHET_HOST') . '/api/v1/', getenv('CACHET_API_KEY'));
$pingdomClient = new Client(getenv('PINGDOM_API_KEY'));


// Parse the metrics mapping
$metricsMap = array_filter(array_map('extractMap', explode(',', env('METRICS_MAP', ''))), function ($map) {
    return !empty($map);
});

// Run the metrics section only if there are metrics mapped
if (!empty($metricsMap)) {
    // Load up the cachet point client to write data points to
    $cachetPoints = PointFactory::build($cachetClient);

    // Run over all the mapped metrics to write new points retrieved from Pingdom
    foreach ($metricsMap as $metricMap) {
        $results = $pingdomClient->getResults($metricMap['pingdom'], (int)env('PINGDOM_RESULT_COUNT', 2));

        foreach ($results as $result) {
            // There is only a response time available when the status is up
            if ($result['status'] === 'up') {
                $point = ['value' => $result['responsetime'], 'timestamp' => $result['time']];

                write("[Metric] Write point from Pingdom check:{$metricMap['pingdom']} to Cachet metric:{$metricMap['cachet']} (" . json_encode($point) . ')');

                $cachetPoints->storePoint($metricMap['cachet'], $point);
            } else {
                write("[Metric] No point for Pingdom check:{$metricMap['pingdom']} to Cachet metric:{$metricMap['cachet']} because status:{$result['status']}");
            }
        }
    }
} else {
    write('[Metric] Section skipped since no (valid) mapping was found.');
}


// Parse the component rules
$componentRules = json_decode(env('COMPONENT_RULES', ''));
// Run the components section only if there are components mapped
if (!empty($componentRules)) {
    // Get the checks from pingdom to map the component statuses
    $pingdomChecks = $pingdomClient->getChecks();

    // Load up the cachet component client to write component updates to
    $cachetComponents = ComponentFactory::build($cachetClient);

    // Run over all the checks and execute updates if needed
    foreach ($componentRules as $componentId => $rule) {
        $cachetComponent = $cachetComponents->getComponent($componentId);
        if (empty($cachetComponent['data']['id'])) {
            write("[Component] Skipping rules checks for Component $componentId because it could not be found in Cachet.");
            continue;
        }
        $cachetStatus    = (int)$cachetComponent['data']['status'];
        $newComponentStatus = 0;
        foreach ($rule as $checkId => $status) {
            $pingdomCheck = array_filter($pingdomChecks, function ($map) use ($checkId) {
                return $map['id'] == $checkId;
            });
            if (empty($pingdomCheck)) {
                write("[Component] Skipping Pingdom check because the Pingdom check $checkId could not be found.");
                continue;
            }
            $pingdomStatus = reset($pingdomCheck)['status'] == 'down' ? $status : 1;
            $newComponentStatus = max($newComponentStatus, $pingdomStatus);
        }
        if ($cachetStatus === $newComponentStatus) {
            write("[Component] Skipping update because the status in Cachet is already equal.");
            continue;
        }

        write("[Component] Updating Cachet Component $checkId with status $newComponentStatus");

        $cachetComponents->updateComponent($componentId, [
            'status' => $newComponentStatus,
        ]);
    }
} else {
    write('[Component] Section skipped since no (valid) mapping was found.');
}