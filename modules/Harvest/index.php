<?php
/**
 * Module 11 - Harvest records list.
 */
$pageTitle = 'Harvests';
$activeNav = 'harvest';
require_once __DIR__ . '/../../includes/header.php';

/* ---------- Filters -------------------------------------------------- */

$filterCrop   = get_int('crop_id');
$filterStatus = $_GET['status'] ?? '';
$fromDate     = $_GET['from'] ?? '';
$toDate       = $_GET['to'] ?? '';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 20;

$where  = [];
$params = [];

if ($filterCrop) {
    $where[] = 'cc.crop_id = :crop_id';
    $params[':crop_id'] = $filterCrop;
}

if (in_array($filterStatus, ['draft', 'confirmed', 'cancelled'], true)) {
    $where[] = 'h.status = :status';
    $params[':status'] = $filterStatus;
}

if (valid_date($fromDate)) {
    $where[] = 'h.harvest_date >= :from';
    $params[':from'] = $fromDate;
}

if (valid_date($toDate)) {
    $where[] = 'h.harvest_date <= :to';
    $params[':to'] = $toDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- Totals for the current filter ---------------------------- */

$totalsSql = "SELECT COUNT(*) AS rows_found,
                     COALESCE(SUM(h.net_qty), 0) AS net_total,
                     COALESCE(SUM(h.gross_qty - h.net_qty), 0) AS loss_total,
                     COALESCE(SUM(h.area_harvested_ha), 0) AS area_total
                FROM harvests h
                JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
                $whereSql";

$stmt = db()->prepare($totalsSql);
$stmt->execute($params);
$totals = $stmt->fetch();

$rowsFound  = (int) $totals['rows_found'];
$totalPages = max(1, (int) ceil($rowsFound / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

/* ---------- Page of records ------------------------------------------ */

$listSql = "SELECT h.harvest_id, h.harvest_code, h.harvest_date, h.gross_qty,
                   h.net_qty, h.area_harvested_ha, h.status,
                   c.crop_name, f.field_name, u.unit_code,
                   (h.gross_qty - h.net_qty) AS loss_qty
              FROM harvests h
              JOIN crop_cycles cc ON cc.cycle_id = h.cycle_id
              JOIN crops  c ON c.crop_id  = cc.crop_id
              JOIN fields f ON f.field_id = cc.field_id
              JOIN units  u ON u.unit_id  = h.unit_id
              $whereSql
          ORDER BY h.harvest_date DESC, h.harvest_id DESC
             LIMIT :limit OFFSET :offset";

$stmt = db()->prepare($listSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$harvests = $stmt->fetchAll();

$crops = db()->query('SELECT crop_id, crop_name FROM crops ORDER BY crop_name')->fetchAll();

/** Keeps the current filters when changing page. */
function page_link(int $page): string
{
    $query = array_merge($_GET, ['page' => $page]);
    return '?' . http_build_query($query);
}
?>

<div class="page-head">
  <div>
    <h1>Harvest records</h1>
    <p class="lede">Every harvesting event, the quantity brought in, and what was lost in the field.
      Confirming a record moves the produce into store as a traceable batch.</p>
  </div>
<?php if (user_can(['admin', 'owner', 'manager', 'agronomist'])): ?>
  <a class="btn btn-primary" href="form.php">Record a harvest</a>
<?php endif; ?>
</div>

<dl class="stats">
  <div><dt>Records</dt><dd><?= number_format($rowsFound) ?></dd></div>
  <div><dt>Net harvested</dt><dd><?= fmt_qty($totals['net_total']) ?></dd></div>
  <div><dt>Field losses</dt><dd><?= fmt_qty($totals['loss_total']) ?></dd></div>
  <div><dt>Area harvested (ha)</dt><dd><?= fmt_qty($totals['area_total'], 2) ?></dd></div>
</dl>

<form class="panel" method="get">
  <div class="body filters">
    <div class="field">
      <label for="crop_id">Crop</label>
      <select name="crop_id" id="crop_id">
        <option value="">All crops</option>
<?php foreach ($crops as $crop): ?>
        <option value="<?= (int) $crop['crop_id'] ?>" <?= $filterCrop === (int) $crop['crop_id'] ? 'selected' : '' ?>>
          <?= e($crop['crop_name']) ?>
        </option>
<?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="status">Status</label>
      <select name="status" id="status">
        <option value="">Any status</option>
<?php foreach (['draft' => 'Draft', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'] as $value => $label): ?>
        <option value="<?= e($value) ?>" <?= $filterStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="from">Harvested from</label>
      <input type="date" name="from" id="from" value="<?= e($fromDate) ?>">
    </div>

    <div class="field">
      <label for="to">To</label>
      <input type="date" name="to" id="to" value="<?= e($toDate) ?>">
    </div>

    <button class="btn" type="submit">Apply</button>
    <a class="btn" href="index.php">Clear</a>
  </div>
</form>

<div class="panel">
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th>Code</th>
          <th>Date</th>
          <th>Crop</th>
          <th>Field</th>
          <th class="num">Area (ha)</th>
          <th class="num">Gross</th>
          <th class="num">Loss</th>
          <th class="num">Net</th>
          <th>Unit</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
<?php if (!$harvests): ?>
        <tr>
          <td colspan="11" class="empty">
            No harvests match these filters. Record one to start tracking yield against your plan.
          </td>
        </tr>
<?php endif; ?>
<?php foreach ($harvests as $row): ?>
        <tr>
          <td><a href="view.php?id=<?= (int) $row['harvest_id'] ?>"><?= e($row['harvest_code']) ?></a></td>
          <td><?= e(fmt_date($row['harvest_date'])) ?></td>
          <td><?= e($row['crop_name']) ?></td>
          <td><?= e($row['field_name']) ?></td>
          <td class="num"><?= fmt_qty($row['area_harvested_ha']) ?></td>
          <td class="num"><?= fmt_qty($row['gross_qty']) ?></td>
          <td class="num"><?= fmt_qty($row['loss_qty']) ?></td>
          <td class="num"><strong><?= fmt_qty($row['net_qty']) ?></strong></td>
          <td><?= e($row['unit_code']) ?></td>
          <td><span class="status is-<?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span></td>
          <td class="actions">
            <a class="btn btn-sm" href="view.php?id=<?= (int) $row['harvest_id'] ?>">Open</a>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="pager">
  <span class="count">Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($rowsFound) ?> records</span>
<?php if ($page > 1): ?>
  <a class="btn btn-sm" href="<?= e(page_link($page - 1)) ?>">Previous</a>
<?php endif; ?>
<?php if ($page < $totalPages): ?>
  <a class="btn btn-sm" href="<?= e(page_link($page + 1)) ?>">Next</a>
<?php endif; ?>
</nav>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
