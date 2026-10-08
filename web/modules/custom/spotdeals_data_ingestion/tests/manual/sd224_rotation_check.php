<?php

declare(strict_types=1);

/**
 * Read-only focused SD-224 selection check against the actual scheduler source.
 * This is NOT a processCron integration test; no Drupal bootstrap is required.
 */
$source = dirname(__DIR__, 2) . '/src/Service/DealDiscoveryScheduler.php';
$code = file_get_contents($source);
if ($code === false) {
  throw new RuntimeException("Cannot read $source");
}
$start = strpos($code, '    $categoryNames = [];');
$end = strpos($code, "    if (!\$this->lock->acquire(", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
  throw new RuntimeException('Scheduler selection section changed; review the test before using it.');
}
$selection = substr($code, $start, $end - $start);
$cases = [
  ['name' => 'Brewery to Entertainment', 'previous' => 43, 'eligible' => [43, 2293, 1785, 3112], 'expected' => 2293],
  ['name' => 'Entertainment to Restaurant', 'previous' => 2293, 'eligible' => [43, 2293, 1785, 3112], 'expected' => 1785],
  ['name' => 'Restaurant to Winery', 'previous' => 1785, 'eligible' => [43, 2293, 1785, 3112], 'expected' => 3112],
  ['name' => 'Winery wraps to Brewery', 'previous' => 3112, 'eligible' => [43, 2293, 1785, 3112], 'expected' => 43],
  ['name' => 'Skip ineligible Entertainment', 'previous' => 43, 'eligible' => [43, 1785, 3112], 'expected' => 1785],
  ['name' => 'Previously selected category disappears', 'previous' => 2293, 'eligible' => [43, 1785, 3112], 'expected' => 43],
];
$names = [43 => 'Brewery', 2293 => 'Entertainment', 1785 => 'Restaurant / Bar', 3112 => 'Winery'];
$failed = 0;
foreach ($cases as $case) {
  $due = [];
  foreach ($case['eligible'] as $tid) {
    $due[] = [
      'tid' => $tid,
      'definition' => ['name' => $names[$tid]],
      'last_completed' => 0,
      'location' => ['label' => 'Alexandria'],
      'token' => 'alexandria',
    ];
  }
  // Replace only the state lookup with the isolated test input.
  $isolated = str_replace(
    '$this->state->get(self::LAST_CATEGORY_STATE_NAME, 0)',
    '(int) $case[\'previous\']',
    $selection,
  );
  if ($isolated === $selection) {
    throw new RuntimeException('Unable to isolate the scheduler state read.');
  }
  $nextCategory = null;
  eval($isolated);
  $passed = $nextCategory === $case['expected'];
  echo ($passed ? 'PASS' : 'FAIL') . ': ' . $case['name'] . ' (got ' . $nextCategory . ', expected ' . $case['expected'] . ")\n";
  $failed += (int) !$passed;
}
exit($failed === 0 ? 0 : 1);
