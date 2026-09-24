<?php
/**
 * Module 15 - Log a quality inspection. No stock movement — this is
 * an observation, not a quantity change.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'storekeeper', 'agronomist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('batches.php');
}

csrf_check();

$batchId       = (int) post('batch_id', 0);
$inspectedOn   = post('inspected_on');
$moisture      = post('moisture_pct') !== '' ? (float) post('moisture_pct') : null;
$temperature   = post('temperature_c') !== '' ? (float) post('temperature_c') : null;
$pestDamage    = post('pest_damage_pct') !== '' ? (float) post('pest_damage_pct') : null;
$overallResult = post('overall_result', 'pass');
$findings      = post('findings') ?: null;
$actionTaken   = post('action_taken') ?: null;

$errors = [];

if (!valid_date($inspectedOn)) {
    $errors[] = 'Enter a valid inspection date.';
}
if (!in_array($overallResult, ['pass', 'conditional', 'fail'], true)) {
    $errors[] = 'Choose a valid result.';
}
foreach (['moisture_pct' => $moisture, 'pest_damage_pct' => $pestDamage] as $label => $value) {
    if ($value !== null && ($value < 0 || $value > 100)) {
        $errors[] = ucfirst(str_replace('_', ' ', $label)) . ' must be between 0 and 100.';
    }
}

if ($errors) {
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect("batch_view.php?id=$batchId");
}

$stmt = db()->prepare('SELECT batch_id FROM produce_batches WHERE batch_id = :id');
$stmt->execute([':id' => $batchId]);

if (!$stmt->fetch()) {
    flash('error', 'That batch no longer exists.');
    redirect('batches.php');
}

db()->prepare(
    "INSERT INTO quality_inspections
       (batch_id, inspected_on, moisture_pct, temperature_c, pest_damage_pct,
        overall_result, findings, action_taken, inspector_id)
     VALUES
       (:batch_id, :inspected_on, :moisture, :temperature, :pest,
        :result, :findings, :action, :user_id)"
)->execute([
    ':batch_id' => $batchId, ':inspected_on' => $inspectedOn, ':moisture' => $moisture,
    ':temperature' => $temperature, ':pest' => $pestDamage, ':result' => $overallResult,
    ':findings' => $findings, ':action' => $actionTaken, ':user_id' => current_user()['user_id'],
]);

flash('success', 'Inspection logged.');
redirect("batch_view.php?id=$batchId");
