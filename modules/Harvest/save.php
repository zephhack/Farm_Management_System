<?php
/**
 * Module 11 - Insert or update a harvest record.
 * Never reached directly; it only handles POST from form.php.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_role(['admin', 'owner', 'manager', 'agronomist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

csrf_check();

$harvestId    = (int) post('harvest_id', 0);
$cycleId      = (int) post('cycle_id', 0);
$planId       = post('plan_id') ? (int) post('plan_id') : null;
$harvestDate  = post('harvest_date');
$areaHa       = (float) post('area_harvested_ha', 0);
$grossQty     = (float) post('gross_qty', 0);
$unitId       = (int) post('unit_id', 0);
$method       = post('method', 'manual');
$supervisorId = post('supervisor_id') ? (int) post('supervisor_id') : null;
$weatherNote  = post('weather_note') ?: null;

/* ---------- Validation ------------------------------------------------ */

$errors = [];

if ($cycleId <= 0) {
    $errors[] = 'Choose the crop cycle this harvest came from.';
}

if (!valid_date($harvestDate)) {
    $errors[] = 'Enter a valid harvest date.';
} elseif ($harvestDate > date('Y-m-d')) {
    $errors[] = 'The harvest date cannot be in the future.';
}

if ($grossQty <= 0) {
    $errors[] = 'Gross quantity must be greater than zero.';
}

if ($areaHa < 0) {
    $errors[] = 'Area harvested cannot be negative.';
}

if ($unitId <= 0) {
    $errors[] = 'Choose a unit of measure.';
}

if (!in_array($method, ['manual', 'mechanical', 'mixed'], true)) {
    $errors[] = 'Choose a valid harvesting method.';
}

/* Area harvested cannot exceed what was planted. */
if ($cycleId > 0 && $areaHa > 0) {
    $stmt = db()->prepare('SELECT area_planted_ha, planting_date FROM crop_cycles WHERE cycle_id = :id');
    $stmt->execute([':id' => $cycleId]);
    $cycle = $stmt->fetch();

    if (!$cycle) {
        $errors[] = 'That crop cycle no longer exists.';
    } else {
        if ($areaHa > (float) $cycle['area_planted_ha'] + 0.001) {
            $errors[] = 'Area harvested is larger than the area planted ('
                      . fmt_qty($cycle['area_planted_ha']) . ' ha).';
        }
        if ($cycle['planting_date'] && $harvestDate < $cycle['planting_date']) {
            $errors[] = 'The harvest date is before the crop was planted.';
        }
    }
}

if ($errors) {
    $_SESSION['old'] = $_POST;
    foreach ($errors as $error) {
        flash('error', $error);
    }
    redirect($harvestId ? "form.php?id=$harvestId" : 'form.php');
}

/* ---------- Save ------------------------------------------------------- */

$pdo = db();

try {
    $pdo->beginTransaction();

    if ($harvestId > 0) {
        /* Editing: still a draft? */
        $stmt = $pdo->prepare('SELECT status, harvest_code FROM harvests WHERE harvest_id = :id FOR UPDATE');
        $stmt->execute([':id' => $harvestId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            throw new RuntimeException('That harvest record no longer exists.');
        }

        if ($existing['status'] !== 'draft') {
            throw new RuntimeException('Confirmed harvests cannot be edited.');
        }

        $sql = "UPDATE harvests
                   SET cycle_id = :cycle_id,
                       plan_id = :plan_id,
                       harvest_date = :harvest_date,
                       area_harvested_ha = :area_ha,
                       gross_qty = :gross_qty,
                       unit_id = :unit_id,
                       method = :method,
                       weather_note = :weather_note,
                       supervisor_id = :supervisor_id
                 WHERE harvest_id = :id";

        $pdo->prepare($sql)->execute([
            ':cycle_id'      => $cycleId,
            ':plan_id'       => $planId,
            ':harvest_date'  => $harvestDate,
            ':area_ha'       => $areaHa,
            ':gross_qty'     => $grossQty,
            ':unit_id'       => $unitId,
            ':method'        => $method,
            ':weather_note'  => $weatherNote,
            ':supervisor_id' => $supervisorId,
            ':id'            => $harvestId,
        ]);

        $code    = $existing['harvest_code'];
        $message = "Harvest $code updated.";
    } else {
        $code = next_code('harvests', 'harvest_code', 'HRV');

        $sql = "INSERT INTO harvests
                  (harvest_code, cycle_id, plan_id, harvest_date, area_harvested_ha,
                   gross_qty, net_qty, unit_id, method, weather_note, supervisor_id,
                   status, recorded_by)
                VALUES
                  (:code, :cycle_id, :plan_id, :harvest_date, :area_ha,
                   :gross_qty, :gross_qty2, :unit_id, :method, :weather_note, :supervisor_id,
                   'draft', :recorded_by)";

        $pdo->prepare($sql)->execute([
            ':code'          => $code,
            ':cycle_id'      => $cycleId,
            ':plan_id'       => $planId,
            ':harvest_date'  => $harvestDate,
            ':area_ha'       => $areaHa,
            ':gross_qty'     => $grossQty,
            ':gross_qty2'    => $grossQty,   // net starts equal to gross
            ':unit_id'       => $unitId,
            ':method'        => $method,
            ':weather_note'  => $weatherNote,
            ':supervisor_id' => $supervisorId,
            ':recorded_by'   => current_user()['user_id'],
        ]);

        $harvestId = (int) $pdo->lastInsertId();
        $message   = "Harvest $code saved as a draft. Add losses and labour, then confirm it.";

        /* Move the cycle into its harvesting phase. */
        $pdo->prepare("UPDATE crop_cycles SET status = 'harvesting'
                        WHERE cycle_id = :id AND status = 'growing'")
            ->execute([':id' => $cycleId]);
    }

    /* Net always reflects the losses currently on file. */
    recalc_harvest_net($harvestId);

    $pdo->commit();
    flash('success', $message);
    redirect("view.php?id=$harvestId");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['old'] = $_POST;
    flash('error', 'Could not save the harvest: ' . $e->getMessage());
    redirect($harvestId ? "form.php?id=$harvestId" : 'form.php');
}
