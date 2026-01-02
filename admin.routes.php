<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : admin.routes.php
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

if (!defined('INDEX_AUTH')) define('INDEX_AUTH', '1');

global $dbs, $sysconf;

// **PERBAIKAN PENTING: Ikuti pattern plugin lain**
// IP based access limitation
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-system');

// Start the session
require SB . 'admin/default/session.inc.php';
require SB . 'admin/default/session_check.inc.php'; // **PERBAIKAN: Ini yang hilang**

require SIMBIO.'simbio_GUI/form_maker/simbio_form_table_AJAX.inc.php';
require SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
require SIMBIO.'simbio_GUI/paging/simbio_paging.inc.php';
require SIMBIO.'simbio_DB/datagrid/simbio_dbgrid.inc.php';
require SIMBIO.'simbio_DB/simbio_dbop.inc.php';
require SIMBIO.'simbio_FILE/simbio_file_upload.inc.php';
require SIMBIO.'simbio_FILE/simbio_directory.inc.php';

// Cek privileges
$can_read  = utility::havePrivilege('system', 'r');
$can_write = utility::havePrivilege('system', 'w');



/**
 * Base query plugin
 */
function ux_base_query(): array {
  $q = [];
  foreach (['mod','id','sec'] as $k) {
    if (isset($_GET[$k])) $q[$k] = $_GET[$k];
  }
  // fallback aman
  if (!isset($q['mod'])) $q['mod'] = 'system';
  if (!isset($q['id']))  $q['id']  = 'user-extended';
  return $q;
}

/**
 * Build URL
 */
function ux_url(array $add = [], array $remove = []): string {
  $q = $_GET;

  foreach ($remove as $k) {
    unset($q[$k]);
  }
  foreach ($add as $k => $v) {
    $q[$k] = $v;
  }

  // pastikan selalu ada mod & id plugin
  $base = ux_base_query();
  foreach ($base as $k => $v) {
    if (!isset($q[$k])) $q[$k] = $v;
  }

  return $_SERVER['PHP_SELF'] . '?' . http_build_query($q);
}

/**
 * callback: user_type
 */
function ux_getUserType($obj_db, $array_data, $col) {
  global $sysconf;
  $key = $array_data[$col];
  return $sysconf['system_user_type'][$key] ?? $key;
}

/**
 * callback
 */
function ux_linkToEdit($obj_db, $array_data, $col) {
  $uid = (int)$array_data[$col];
  $url = ux_url(['action'=>'detail','uid'=>$uid], []);
  return '<a class="notAJAX" href="'.$url.'">'.htmlspecialchars((string)$uid).'</a>';
}

/**
 * === DELETE SELECTED DATA HANDLER ===
 */
if (isset($_POST['itemID']) && !empty($_POST['itemID']) && isset($_POST['itemAction'])) {
  // Hanya admin yang bisa delete user
  if ($_SESSION['uid'] != 1 || !$can_write) {
    die('<div class="errorBox">'.__('You don\'t have enough privileges.').'</div>');
  }

  $sql_op = new simbio_dbop($dbs);
  $error_num = 0;

  $ids = $_POST['itemID'];
  if (!is_array($ids)) $ids = [(int)$ids];

  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id <= 1) 

    // log name
    $uq = $dbs->query('SELECT username, realname FROM user WHERE user_id='.$id);
    $ud = $uq ? $uq->fetch_row() : null;

    if (!$sql_op->delete('user', "user_id='{$id}'")) {
      $error_num++;
    } else {
      writeLog('staff', $_SESSION['uid'], 'system', $_SESSION['realname'].' DELETE user ('.$ud[1].') with username ('.$ud[0].')', 'UserExtended', 'Delete');
    }
  }

  if ($error_num == 0) {
    toastr(__('All Data Successfully Deleted'))->success();
  } else {
    toastr(__('Some data failed to delete!'))->error();
  }

  // reload ke list plugin
  echo '<script type="text/javascript">parent.$("#mainContent").simbioAJAX("'.ux_url(['action'=>'index'], ['uid']).'");</script>';
  exit;
}

// ROUTING
$action = $_GET['action'] ?? 'index';

switch ($action) {
  case 'detail':
    require __DIR__ . '/pages/user_form.php';
    break;

  case 'attach':
    require __DIR__ . '/pages/pop_attach_user.php';
    break;

  case 'iframe_attach':
  case 'iframe_attach_user':
    require __DIR__ . '/pages/iframe_attach_user.php';
    break;

  case 'pop_attach_user':
    require __DIR__ . '/pages/pop_attach_user.php';
    break;

  default:
    require __DIR__ . '/pages/user_list.php';
}