<?php
/**
 * Module 12 - Market price monitoring.
 * Prices logged here are what makes price-trend and best-selling-period
 * analysis possible later; this page just captures the raw observations.
 */
$pageTitle = 'Market prices';
$activeNav = 'prices';
require_once __DIR__ . '/../../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['admin', 'owner', 'manager', 'agronomist']);
    csrf_check();

    $cropId       = (int) post('crop_id', 0);
    $destinationId = post('destination_id') ? (int) post('destination_id') : null;
    $grade        = post('grade', 'ungraded');
    $price        = (float) post('price', 0);
    $unitId       = (int) post('unit_id', 0);
    $priceDate    = post('price_date');
    $source       = post('source') ?: null;

    $errors = [];
    if ($cropId <= 0)              { $errors[] = 'Choose a crop.'; }
    if ($price <= 0)               { $errors[] = 'Price must be greater than zero.'; }
    if ($unitId <= 0)              { $errors[] = 'Choose a unit.'; }
    if (!valid_date($priceDate))   { $errors[] = 'Enter a valid price date.'; }

    if ($errors) {
        foreach ($errors as $error) {
            flash('error', $error);
        }
    } else {
        try {
            db()->prepare(
                "INSERT INTO market_prices
                   (crop_id, destination_id, grade, price, unit_id, price_date, source, recorded_by)
                 VALUES
                   (:crop_id, :destination_id, :grade, :price, :unit_id, :price_date, :source, :user_id)
                 ON DUPLICATE KEY UPDATE price = VALUES(price), source = VALUES(source)"
            )->execute([
                ':crop_id' => $cropId, ':destination_id' => $destinationId, ':grade' => $grade,
                ':price' => $price, ':unit_id' => $unitId, ':price_date' => $priceDate,
                ':source' => $source, ':user_id' => current_user()['user_id'],
            ]);
            flash('success', 'Price recorded.');
        } catch (Throwable $e) {
            flash('error', 'Could not save the price: ' . $e->getMessage());
        }
    }

    redirect('market_prices.php');
}

require_once __DIR__ . '/../../includes/header.php';

$cropFilter = get_int('crop_id');
$where  = $cropFilter ? 'WHERE mp.crop_id = :crop_id' : '';
$params = $cropFilter ? [':crop_id' => $cropFilter] : [];

$stmt = db()->prepare(
    "SELECT mp.*, c.crop_name, u.unit_code, md.market_name
       FROM market_prices mp
       JOIN crops c ON c.crop_id = mp.crop_id
       JOIN units u ON u.unit_id = mp.unit_id
  LEFT JOIN market_destinations md ON md.destination_id = mp.destination_id
       $where
   ORDER BY mp.price_date DESC
      LIMIT 100"
);
$stmt->execute($params);
$prices = $stmt->fetchAll();

$crops        = db()->query('SELECT crop_id, crop_name FROM crops ORDER BY crop_name')->fetchAll();
$destinations = db()->query('SELECT destination_id, market_name FROM market_destinations ORDER BY market_name')->fetchAll();
$units        = db()->query('SELECT unit_id, unit_code FROM units ORDER BY unit_code')->fetchAll();
$canManage    = user_can(['admin', 'owner', 'manager', 'agronomist']);
?>

<div class="page-head">
  <div>
    <h1>Market prices</h1>
    <p class="lede">What produce is fetching at each market. Feeds the price-trend
      picture used when deciding the best time and place to sell.</p>
  </div>
</div>

<form class="panel" method="get">
  <div class="body filters">
    <div class="field">
      <label for="crop_id">Crop</label>
      <select name="crop_id" id="crop_id" onchange="this.form.submit()">
        <option value="">All crops</option>
<?php foreach ($crops as $crop): ?>
        <option value="<?= (int) $crop['crop_id'] ?>" <?= $cropFilter === (int) $crop['crop_id'] ? 'selected' : '' ?>>
          <?= e($crop['crop_name']) ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th>Date</th><th>Crop</th><th>Grade</th><th>Market</th><th class="num">Price</th><th>Source</th></tr>
      </thead>
      <tbody>
<?php if (!$prices): ?>
        <tr><td colspan="6" class="empty">No prices logged yet.</td></tr>
<?php endif; ?>
<?php foreach ($prices as $p): ?>
        <tr>
          <td><?= e(fmt_date($p['price_date'])) ?></td>
          <td><?= e($p['crop_name']) ?></td>
          <td><?= e($p['grade']) ?></td>
          <td><?= e($p['market_name'] ?? 'Unspecified') ?></td>
          <td class="num"><?= fmt_money($p['price']) ?> / <?= e($p['unit_code']) ?></td>
          <td><?= e($p['source'] ?? '—') ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="panel">
  <header>Log a price</header>
  <div class="body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="filters">
        <div class="field">
          <label for="crop_id2">Crop</label>
          <select name="crop_id" id="crop_id2" required>
<?php foreach ($crops as $crop): ?>
            <option value="<?= (int) $crop['crop_id'] ?>"><?= e($crop['crop_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="grade">Grade</label>
          <select name="grade" id="grade">
            <option value="ungraded">Ungraded</option>
            <option value="A">A</option><option value="B">B</option><option value="C">C</option>
          </select>
        </div>
        <div class="field">
          <label for="destination_id">Market</label>
          <select name="destination_id" id="destination_id">
            <option value="">Not specified</option>
<?php foreach ($destinations as $d): ?>
            <option value="<?= (int) $d['destination_id'] ?>"><?= e($d['market_name']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="price">Price</label>
          <input type="number" name="price" id="price" step="0.01" min="0.01" required>
        </div>
        <div class="field">
          <label for="unit_id">Per unit</label>
          <select name="unit_id" id="unit_id" required>
<?php foreach ($units as $u): ?>
            <option value="<?= (int) $u['unit_id'] ?>"><?= e($u['unit_code']) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="price_date">Date</label>
          <input type="date" name="price_date" id="price_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
          <label for="source">Source</label>
          <input type="text" name="source" id="source" maxlength="120" placeholder="Market survey, ZAMACE, ...">
        </div>
        <button class="btn btn-primary" type="submit">Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
