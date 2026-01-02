<?php

/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : iframe_attach_user.phpp
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


use SLiMS\Filesystems\Storage;

defined('INDEX_AUTH') OR define('INDEX_AUTH', '1');

global $dbs, $sysconf;

// session check (jangan start session baru)
require_once SB . 'admin/default/session_check.inc.php';


if (!class_exists('simbio_table', false)) {
  require_once SIMBIO.'simbio_GUI/table/simbio_table.inc.php';
}
if (!class_exists('simbio_dbop', false)) {
  require_once SIMBIO.'simbio_DB/simbio_dbop.inc.php';
}

$can_read  = utility::havePrivilege('system', 'r');
$can_write = utility::havePrivilege('system', 'w');

if (!$can_read) {
  die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}

// plugin identity params (WAJIB)
$mod = $_GET['mod'] ?? 'system';
$pid = $_GET['id']  ?? '';
$sec = $_GET['sec'] ?? '';

$userID = (int)($_GET['userID'] ?? 0);
$block  = (int)($_GET['block'] ?? 0);

if ($userID < 1) {
  die('<div class="infoBox">'.__('No user selected.').'</div>');
}

$build_url = function(array $params = []) use ($mod, $pid, $sec) {
  $base = ['mod' => $mod, 'id' => $pid];
  if (!empty($sec)) $base['sec'] = $sec;
  return $_SERVER['PHP_SELF'].'?'.http_build_query(array_merge($base, $params));
};

$self_url = $build_url([
  'action' => 'iframe_attach_user',
  'userID' => $userID,
  'block'  => $block ?: 1
]);


if (isset($_POST['remove_relation']) && $can_write) {
  $relation_id = (int)($_POST['relation_id'] ?? 0);
  $file_id     = (int)($_POST['file_id'] ?? 0);
  $alsoDelete  = (int)($_POST['alsoDeleteFile'] ?? 0);

  if ($relation_id > 0) {
    $sql_op = new simbio_dbop($dbs);

    // ambil info file
    $file_d = null;
    if ($file_id > 0) {
      $fq = $dbs->query('SELECT file_dir, file_name FROM files WHERE file_id='.(int)$file_id);
      $file_d = $fq ? $fq->fetch_assoc() : null;
    }

    // hapus relasi
    $ok = $sql_op->delete('user_attachment', 'id='.$relation_id.' AND user_id='.$userID);

    if ($ok) {
      
      if ($alsoDelete === 1 && $file_id > 0 && $file_d) {
        try {
          $repo = Storage::repository();
          $path = trim((string)$file_d['file_dir'], '/');
          $name = trim((string)$file_d['file_name'], '/');
          if ($name !== '') {
            $full = ($path !== '' ? $path.'/' : '').$name;
            @$repo->delete(str_replace('/', DS, $full));
          }
        } catch (Throwable $e) {
          // ignore
        }
        // hapus record files 
        $sql_op->delete('files', 'file_id='.$file_id);
      }

      writeLog(
        'staff',
        $_SESSION['uid'],
        'system',
        $_SESSION['realname'].' delete user attachment (relation_id='.$relation_id.', file_id='.$file_id.')',
        'User Attachment',
        'Delete'
      );

      utility::jsToastr('Attachment', __('Attachment removed!'), 'success');
    } else {
      utility::jsToastr('Attachment', __('Failed to delete attachment')."\n".$sql_op->error, 'error');
    }
  }

  echo '<script type="text/javascript">location.href = "'.$self_url.'";</script>';
  exit;
}


ob_start();

$user_q = $dbs->query("SELECT realname, username FROM user WHERE user_id={$userID}");
$user_d = $user_q ? $user_q->fetch_assoc() : [];
$user_name = $user_d['realname'] ?? __('Unknown User');

echo '<div class="infoBox" style="margin-bottom:8px;">'
  .__('User').': <b>'.htmlspecialchars($user_name).'</b> (ID: '.(int)$userID.')'
  .'</div>';

$table = new simbio_table();
$table->table_attr = 'align="center" style="width:100%;" cellpadding="2" cellspacing="0"';

$q = $dbs->query("
  SELECT
    ua.id AS relation_id,
    ua.note,
    ua.input_date,
    ua.last_update,
    f.file_id,
    f.file_title,
    f.file_name,
    f.file_url,
    f.file_dir,
    f.mime_type,
    f.file_desc
  FROM user_attachment ua
  LEFT JOIN files f ON f.file_id = ua.file_id
  WHERE ua.user_id = {$userID}
  ORDER BY ua.input_date DESC
");

if ($q && $q->num_rows > 0) {
  $row = 1;
  while ($d = $q->fetch_assoc()) {
    $row_class = ($row % 2 === 0) ? 'alterCell' : 'alterCell2';

    $relation_id = (int)$d['relation_id'];
    $file_id     = (int)$d['file_id'];

    // ===== Delete link (native pattern) =====
    if ($can_write) {
      $remove_link = '<a href="#" onclick="confirmDelete('.$relation_id.', '.$file_id.', \''.addslashes($d['file_name'] ?? '').'\');return false;" class="s-btn btn btn-danger notAJAX">'
        .__('Delete').'</a>';
    } else {
      $remove_link = '<span class="text-muted">-</span>';
    }


    $edit_url = $build_url([
      'action'      => 'pop_attach_user',
      'userID'      => $userID,
      'relation_id' => $relation_id,
      'fileID'      => $file_id,
      'block'       => 1
    ]);

    $edit_link = $can_write
      ? '<a class="s-btn btn btn-default notAJAX openPopUp" href="'.htmlspecialchars($edit_url).'" width="780" height="520" title="'.__('Edit Attachment').'">'
          .__('Edit').'</a>'
      : '<span class="text-muted">-</span>';

    // ===== File link =====
    $label = $d['file_title'] ?: ($d['file_name'] ?: ('File #'.$file_id));
    $label = htmlspecialchars($label);

    // view.php (admin) aman untuk preview/download
    $view_link = $file_id
      ? '<a class="s-btn btn btn-link notAJAX openPopUp" href="'.SWB.'admin/view.php?fid='.urlencode($file_id).'" width="780" height="520" target="_blank">'.$label.'</a>'
      : $label;

    $note = htmlspecialchars((string)($d['note'] ?? $d['file_desc'] ?? ''));
    $date = htmlspecialchars((string)($d['input_date'] ?? ''));

    $table->appendTableRow([$remove_link, $edit_link, $view_link, $note, $date]);

    $table->setCellAttr($row, 0, 'valign="top" class="'.$row_class.'" style="font-weight:bold;width:7%;"');
    $table->setCellAttr($row, 1, 'valign="top" class="'.$row_class.'" style="font-weight:bold;width:7%;"');
    $table->setCellAttr($row, 2, 'valign="top" class="'.$row_class.'" style="width:40%;"');
    $table->setCellAttr($row, 3, 'valign="top" class="'.$row_class.'" style="width:36%;"');
    $table->setCellAttr($row, 4, 'valign="top" class="'.$row_class.'" style="width:10%;white-space:nowrap;"');

    $row++;
  }

  echo $table->printTable();

  // hidden form (WAJIB mod/id)
  echo '<form name="hiddenActionForm" method="post" action="'.htmlspecialchars($self_url).'">
    <input type="hidden" name="mod" value="'.htmlspecialchars($mod).'">
    <input type="hidden" name="id" value="'.htmlspecialchars($pid).'">';
  if (!empty($sec)) {
    echo '<input type="hidden" name="sec" value="'.htmlspecialchars($sec).'">';
  }
  echo '
    <input type="hidden" name="userID" value="'.(int)$userID.'">
    <input type="hidden" name="block" value="1">
    <input type="hidden" name="remove_relation" value="1">
    <input type="hidden" name="relation_id" value="0">
    <input type="hidden" name="file_id" value="0">
    <input type="hidden" name="alsoDeleteFile" value="0">
  </form>';

} else {
  echo '<div class="infoBox">'.__('No attachments found').'</div>';
}

?>
<script type="text/javascript">
function confirmDelete(relationId, fileId, fileName) {
  var ok = confirm('<?php echo addslashes(__('Are you sure to remove the attachment data?')); ?>');
  if (!ok) return;

  // optional: hapus file dari repository juga
  var also = confirm('<?php echo addslashes(__('Do you also want to remove {filename} file from repository?')); ?>'
      .replace('{filename}', fileName || ('File #' + fileId)));

  document.hiddenActionForm.relation_id.value = relationId;
  document.hiddenActionForm.file_id.value = fileId;
  document.hiddenActionForm.alsoDeleteFile.value = also ? '1' : '0';
  document.hiddenActionForm.submit();
}
</script>
<?php

$content = ob_get_clean();
require SB.'/admin/'.$sysconf['admin_template']['dir'].'/notemplate_page_tpl.php';
