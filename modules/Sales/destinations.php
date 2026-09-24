<?php
/**
 * Module 12 - Market destinations (where produce is sold to).
 */
$pageTitle = 'Market destinations';
$activeNav = 'destinations';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager']);
    csrf_check();

    $name       = post('market_name');
    $type       = post('market_type', 'local');
    $location   = post('location') ?: null;
    $distance   = post('distance_km') !== '' ? (float) post('distance_km') : null;
    $transport  = (float) post('transport_cost_per_trip', 0);
    $notes      = post('notes') ?: null;

    $validTypes = ['local', 'district', 'national', 'export', 'farmgate', 'online'];

    if ($name === '' || $name === null) {
        flash('error', 'Enter the market name.');
    } elseif (!in_array($type, $validTypes, true)) {
        flash('error', 'Choose a valid market type.');
    } else {
        db()->prepare(
            "INSERT INTO market_destinations
               (market_name, market_type, location, distance_km, transport_cost_per_trip, notes)
             VALUES (:name, :type, :location, :distance, :transport, :notes)"
        )->execute([
            ':name' => $name, ':type' => $type, ':location' => $location,
            ':distance' => $distance, ':transport' => $transport, ':notes' => $notes,
        ]);
        flash('success', "$name added.");
    }

    redirect('destinations.php');
}

require_once __DIR__ . '/../../includes/header.php';

$destinations = db()->query('SELECT * FROM market_destinations ORDER BY market_name')->fetchAll();
$canManage = user_can(['admin', 'owner', 'manager']);
?>

<div class="page-head">
  <div>
    <h1>Market destinations</h1>
    <p class="lede">Where produce goes to sell — used on sales orders and dispatches
      to estimate transport cost.</p>
  </div>
</div>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Market</th><th>Type</th><th>Location</th><th class="num">Distance (km)</th><th class="num">Transport / trip</th></tr>
      </thead>
      <tbody>
<?php if (!$destinations): ?>
        <tr><td colspan="5" class="empty">No destinations yet.</td></tr>
<?php endif; ?>
<?php foreach ($destinations as $d): ?>
        <tr>
          <td><?= e($d['market_name']) ?></td>
          <td><?= e(ucfirst($d['market_type'])) ?></td>
          <td><?= e($d['location'] ?? '—') ?></td>
          <td class="num"><?= $d['distance_km'] !== null ? fmt_qty($d['distance_km'], 1) : '—' ?></td>
          <td class="num"><?= fmt_money($d['transport_cost_per_trip']) ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Add a destination</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="filters">
        <div class="field" style="flex:1">
          <label for="market_name">Market name</label>
          <input type="text" name="market_name" id="market_name" required maxlength="150">
        </div>
        <div class="field">
          <label for="market_type">Type</label>
          <select name="market_type" id="market_type">
<?php foreach (['local', 'district', 'national', 'export', 'farmgate', 'online'] as $type): ?>
            <option value="<?= e($type) ?>"><?= e(ucfirst($type)) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="location">Location</label>
          <input type="text" name="location" id="location" maxlength="200">
        </div>
        <div class="field">
          <label for="distance_km">Distance (km)</label>
          <input type="number" name="distance_km" id="distance_km" step="0.1" min="0">
        </div>
        <div class="field">
          <label for="transport_cost_per_trip">Transport / trip</label>
          <input type="number" name="transport_cost_per_trip" id="transport_cost_per_trip" step="0.01" min="0" value="0">
        </div>
        <button class="btn btn-primary" type="submit">Add</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
