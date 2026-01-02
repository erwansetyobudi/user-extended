<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : user_list.php
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

defined('INDEX_AUTH') OR die('Direct access not allowed!');

global $dbs, $sysconf;

// Cek privileges
$can_read = utility::havePrivilege('system', 'r');
if (!$can_read) {
    die('<div class="errorBox">'.__('You don\'t have enough privileges to access this area!').'</div>');
}

$can_write = utility::havePrivilege('system', 'w');
$is_admin = ($_SESSION['uid'] == 1);

// plugin identity params
$mod = $_GET['mod'] ?? 'system';
$pid = $_GET['id'] ?? 'user-extended';
$sec = $_GET['sec'] ?? '';
$action = $_GET['action'] ?? 'index';

$keywords = $_GET['keywords'] ?? '';
$keywords = is_string($keywords) ? trim($keywords) : '';

// helper build url with mod/id/sec
function ux_build_url(array $add = []) : string
{
  $mod = $_GET['mod'] ?? 'system';
  $pid = $_GET['id'] ?? 'user-extended';
  $sec = $_GET['sec'] ?? '';

  $q = array_merge(['mod'=>$mod,'id'=>$pid], $add);
  if (!empty($sec)) $q['sec'] = $sec;

  return $_SERVER['PHP_SELF'] . '?' . http_build_query($q);
}

$listUrl = ux_build_url(['action'=>'index']);
$addUrl  = ux_build_url(['action'=>'detail','uid'=>0]);

?>
<div class="menuBox">
  <div class="menuBoxInner userIcon">
    <div class="per_title">
      <h2><?php echo __('User Extended'); ?></h2>
    </div>
    <div class="sub_section">
      <div class="btn-group">
        <a href="<?php echo $listUrl; ?>" class="btn btn-default"><?php echo __('User List'); ?></a>
        <?php if ($is_admin && $can_write): ?>
          <a href="<?php echo $addUrl; ?>" class="btn btn-default"><?php echo __('Add New User'); ?></a>
        <?php endif; ?>
      </div>

      <form name="search" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="get" class="form-inline">
        <input type="hidden" name="mod" value="<?php echo htmlspecialchars($mod); ?>"/>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($pid); ?>"/>
        <?php if (!empty($sec)): ?>
          <input type="hidden" name="sec" value="<?php echo htmlspecialchars($sec); ?>"/>
        <?php endif; ?>
        <input type="hidden" name="action" value="index"/>

        <?php echo __('Search'); ?>
        <input type="text" name="keywords" class="form-control col-md-3" value="<?php echo htmlspecialchars($keywords); ?>"/>
        <input type="submit" value="<?php echo __('Search'); ?>" class="btn btn-default"/>
      </form>
    </div>
  </div>
</div>

<?php

// === DATAGRID ===
require_once SIMBIO.'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require_once SIMBIO.'simbio_DB/simbio_dbop.inc.php';

// table spec
$table_spec = 'user AS u';

// create datagrid
$datagrid = new simbio_datagrid();

// Kolom EDIT
$editUrl = ux_build_url(['action' => 'detail']);

if ($can_write) {
    $editCol = "CONCAT('<a href=\"{$editUrl}&uid=', u.user_id, '\" title=\"".addslashes(__('Edit'))."\" class=\"editLink\"></a>')";
} else {
    $editCol = "'' AS 'EDIT'";
}

// Query criteria
$additional_criteria = '';
if (!$is_admin) {
    // Non-admin tidak bisa melihat admin
    $additional_criteria = " AND u.user_id != 1";
}

// columns
$datagrid->setSQLColumn(
  'u.user_id',
  "{$editCol} AS 'EDIT'",
  "u.realname AS '".__('Real Name')."'",
  "u.username AS '".__('Login Username')."'",
  "u.nip AS 'NIP'",
  "u.phone AS '".__('Phone')."'",
  "u.user_type AS '".__('User Type')."'",
  "u.last_login AS '".__('Last Login')."'",
  "u.last_update AS '".__('Last Update')."'"
);

// ubah user_type jadi label
if (function_exists('ux_getUserType')) {
  $datagrid->modifyColumnContent(6, 'callback{ux_getUserType}');
}

// sorting
$datagrid->setSQLorder('u.username ASC');

// criteria
$criteria = '1=1' . $additional_criteria;
if ($keywords !== '') {
  $kw = $dbs->escape_string($keywords);
  $criteria .= " AND (
    u.username LIKE '%$kw%' OR
    u.realname LIKE '%$kw%' OR
    u.nip LIKE '%$kw%' OR
    u.phone LIKE '%$kw%'
  )";
}
$datagrid->setSQLCriteria($criteria);

// table attributes
$datagrid->table_name = 'userExtendedList';
$datagrid->table_attr = 'id="dataList" class="s-table table"';
$datagrid->table_header_attr = 'class="dataListHeader" style="font-weight: bold;"';

// Hanya admin yang bisa delete
if ($is_admin && $can_write) {
    $datagrid->chbox_form_URL = ux_build_url([
      'action' => 'index',
      'keywords' => $keywords
    ]);
} else {
    $datagrid->chbox_form_URL = '';
}

// create grid
echo $datagrid->createDataGrid($dbs, $table_spec, 20, ($is_admin && $can_write));

// result info
if ($keywords !== '') {
  echo '<div class="infoBox">';
  echo sprintf(__('Found <strong>%d</strong> from your keywords'), (int)$datagrid->num_rows) . ' : "'.htmlspecialchars($keywords).'"';
  echo '</div>';
}