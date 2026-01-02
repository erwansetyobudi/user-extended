<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : pop_attach_user.php
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

// session sudah ada dari plugin_container
require_once SB . 'admin/default/session_check.inc.php';

// include
if (!class_exists('simbio_dbop', false)) {
  require_once SIMBIO . 'simbio_DB/simbio_dbop.inc.php';
}
if (!class_exists('simbio_directory', false)) {
  require_once SIMBIO . 'simbio_FILE/simbio_directory.inc.php';
}

$can_write = utility::havePrivilege('system', 'w');
if (!$can_write) {
  die('<div class="errorBox">'.__('You are not authorized to view this section').'</div>');
}

// plugin identity
$mod = $_GET['mod'] ?? 'system';
$pid = $_GET['id']  ?? '';
$sec = $_GET['sec'] ?? '';

$build_url = function(array $params = []) use ($mod, $pid, $sec) {
  $base = ['mod' => $mod, 'id' => $pid];
  if (!empty($sec)) $base['sec'] = $sec;
  return $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($base, $params));
};

// userID GET > POST > session
$userID = 0;
if (isset($_GET['userID'])) $userID = (int)$_GET['userID'];
elseif (isset($_POST['userID'])) $userID = (int)$_POST['userID'];
elseif (isset($_SESSION['last_attachment_user_id'])) $userID = (int)$_SESSION['last_attachment_user_id'];

if ($userID > 0) $_SESSION['last_attachment_user_id'] = $userID;

if ($userID < 1) {
  die('<div class="errorBox">'.__('Invalid User ID').'</div>');
}

// edit params
$relation_id = (int)($_GET['relation_id'] ?? $_POST['relation_id'] ?? 0);
$fileID      = (int)($_GET['fileID'] ?? $_POST['fileID'] ?? 0);
$is_edit     = ($relation_id > 0 && $fileID > 0);

// user info
$user_q = $dbs->query("SELECT realname, username FROM user WHERE user_id={$userID}");
$user_d = $user_q ? $user_q->fetch_assoc() : [];
$user_name = $user_d['realname'] ?? __('Unknown User');

// repo directory options
$repodir_options = [];
$repodir_options[] = ['', __('Repository ROOT')];
try {
  $repo = new simbio_directory(REPOBS);
  $tree = $repo->getDirectoryTree(5);
  if (is_array($tree)) {
    ksort($tree);
    foreach ($tree as $dir) $repodir_options[] = [$dir, $dir];
  }
} catch (Throwable $e) { /* ignore */ }

// default form data
$edit_d = [
  'file_title' => '',
  'file_url'   => '',
  'file_dir'   => '',
  'file_desc'  => '',
  'note'       => '',
  'file_name'  => '',
  'mime_type'  => '',
];

// load existing data when edit
if ($is_edit) {
  $eq = $dbs->query("
    SELECT ua.id AS relation_id, ua.note,
           f.file_id, f.file_title, f.file_url, f.file_dir, f.file_desc, f.file_name, f.mime_type
    FROM user_attachment ua
    LEFT JOIN files f ON f.file_id = ua.file_id
    WHERE ua.id={$relation_id} AND ua.user_id={$userID} AND ua.file_id={$fileID}
    LIMIT 1
  ");
  $row = $eq ? $eq->fetch_assoc() : null;
  if ($row) {
    $edit_d = array_merge($edit_d, $row);
  } else {
    // kalau tidak ketemu, jatuhkan ke mode add supaya tidak blank error
    $is_edit = false;
    $relation_id = 0;
    $fileID = 0;
  }
}

// iframe url untuk refresh list
$iframe_url = $build_url([
  'action' => 'iframe_attach_user',
  'userID' => $userID,
  'block'  => 1
]);

// Process Submit

if (isset($_POST['save'])) {
  $title    = trim(strip_tags((string)($_POST['fileTitle'] ?? '')));
  $file_dir = trim((string)($_POST['fileDir'] ?? ''));
  $url      = trim(strip_tags((string)($_POST['fileURL'] ?? '')));
  $desc     = trim(strip_tags((string)($_POST['fileDesc'] ?? '')));

  if ($title === '') {
    utility::jsToastr('Attachment', __('Title is required'), 'error');
  } else {
    $sql_op = new simbio_dbop($dbs);

    // ===== EDIT MODE =====
    if ($is_edit) {
      // optional replace file
      $replace_ok = false;
      $new_file_name = $edit_d['file_name'];
      $new_mime_type = $edit_d['mime_type'];

      if (isset($_FILES['file2attach']) && (int)($_FILES['file2attach']['size'] ?? 0) > 0) {
        if (!empty($_FILES['file2attach']['error']) && (int)$_FILES['file2attach']['error'] === 1) {
          utility::jsToastr('Attachment', __('Invalid attachment, file exceeds server max upload'), 'error');
        } else {
          $file_upload = Storage::repository()->upload('file2attach', function($repository) use ($sysconf) {
            $repository->isExtensionAllowed();
            $repository->isLimitExceeded($sysconf['max_upload'] * 1024);
            if (!empty($repository->getError())) $repository->destroyIfFailed();
          })->as(md5(date('Y-m-d H:i:s')));

          if ($file_upload->getUploadStatus()) {
            $replace_ok = true;
            $new_file_name = $file_upload->getUploadedFileName();
            $file_ext = $file_upload->getExt($new_file_name);
            $new_mime_type = $sysconf['mimetype'][trim($file_ext, '.')] ?? $edit_d['mime_type'];

            // (opsional) hapus file lama fisik kalau mau:
            // try { Storage::repository()->delete(str_replace('/', DS, trim($edit_d['file_dir'],'/').'/'.$edit_d['file_name'])); } catch(Throwable $e){}
          } else {
            utility::jsToastr('Attachment', __('Upload FAILED! Forbidden file type or file size too big!'), 'error');
          }
        }
      }

      // update files table
      $f_update = [
        'file_title'  => $dbs->escape_string($title),
        'file_url'    => $dbs->escape_string($url),
        'file_dir'    => $dbs->escape_string($file_dir),
        'file_desc'   => $dbs->escape_string($desc),
        'last_update' => date('Y-m-d H:i:s')
      ];
      if ($replace_ok) {
        $f_update['file_name'] = $dbs->escape_string($new_file_name);
        $f_update['mime_type'] = $dbs->escape_string($new_mime_type);
      }

      $ok1 = $sql_op->update('files', $f_update, 'file_id='.$fileID);

      // update user_attachment note
      $ua_update = [
        'note'        => $dbs->escape_string($desc),
        'last_update' => date('Y-m-d H:i:s')
      ];
      $ok2 = $sql_op->update('user_attachment', $ua_update, 'id='.$relation_id.' AND user_id='.$userID.' AND file_id='.$fileID);

      if ($ok1 && $ok2) {
        writeLog('staff', $_SESSION['uid'], 'system',
          $_SESSION['realname'].' update user attachment (relation_id='.$relation_id.', file_id='.$fileID.')',
          'User Attachment', 'Update'
        );

        utility::jsToastr('Attachment', __('Attachment updated successfully!'), 'success');

        echo '<script type="text/javascript">
          try {
            if (parent && typeof parent.setIframeContent === "function") {
              parent.setIframeContent("attachIframe", "'.addslashes($iframe_url).'");
            } else if (parent && parent.document) {
              var ifr = parent.document.getElementById("attachIframe");
              if (ifr) ifr.src = ifr.src;
            }
            if (parent && parent.$ && parent.$.colorbox) parent.$.colorbox.close();
          } catch(e) { console.log(e); }
        </script>';
      } else {
        utility::jsToastr('Attachment', __('Failed to update attachment')."\n".$sql_op->error, 'error');
      }
    }

    // ===== ADD MODE =====
    else {
      $uploaded_file_id = 0;

      // 1) upload file
      if (isset($_FILES['file2attach']) && (int)($_FILES['file2attach']['size'] ?? 0) > 0) {
        if (!empty($_FILES['file2attach']['error']) && (int)$_FILES['file2attach']['error'] === 1) {
          utility::jsToastr('Attachment', __('Invalid attachment, file exceeds server max upload'), 'error');
        } else {
          $file_upload = Storage::repository()->upload('file2attach', function($repository) use ($sysconf) {
            $repository->isExtensionAllowed();
            $repository->isLimitExceeded($sysconf['max_upload'] * 1024);
            if (!empty($repository->getError())) $repository->destroyIfFailed();
          })->as(md5(date('Y-m-d H:i:s')));

          if ($file_upload->getUploadStatus()) {
            $new_file_name = $file_upload->getUploadedFileName();
            $file_ext = $file_upload->getExt($new_file_name);

            $fdata = [];
            $fdata['uploader_id'] = $_SESSION['uid'];
            $fdata['file_title']  = $dbs->escape_string($title);
            $fdata['file_name']   = $dbs->escape_string($new_file_name);
            $fdata['file_url']    = $dbs->escape_string($url);
            $fdata['file_dir']    = $dbs->escape_string($file_dir);
            $fdata['file_desc']   = $dbs->escape_string($desc);
            $fdata['mime_type']   = $sysconf['mimetype'][trim($file_ext, '.')] ?? '';
            $fdata['input_date']  = date('Y-m-d H:i:s');
            $fdata['last_update'] = $fdata['input_date'];

            @$sql_op->insert('files', $fdata);
            $uploaded_file_id = $sql_op->insert_id;

            writeLog('staff', $_SESSION['uid'], 'system',
              $_SESSION['realname'].' upload user attachment file ('.$new_file_name.')',
              'User Attachment', 'Add'
            );
          } else {
            utility::jsToastr('Attachment', __('Upload FAILED! Forbidden file type or file size too big!'), 'error');
          }
        }
      } else {
        // 2) URL only
        if ($url && preg_match('@^(http|https|ftp|gopher):\/\/@i', $url)) {
          $fdata = [];
          $fdata['uploader_id'] = $_SESSION['uid'];
          $fdata['file_title']  = $dbs->escape_string($title);
          $fdata['file_name']   = $dbs->escape_string($url);
          $fdata['file_url']    = $dbs->escape_string($url);
          $fdata['file_dir']    = 'literal{NULL}';
          $fdata['file_desc']   = $dbs->escape_string($desc);
          $fdata['mime_type']   = 'text/uri-list';
          $fdata['input_date']  = date('Y-m-d H:i:s');
          $fdata['last_update'] = $fdata['input_date'];

          @$sql_op->insert('files', $fdata);
          $uploaded_file_id = $sql_op->insert_id;
        } else {
          utility::jsToastr('Attachment', __('Please choose a file or provide a valid URL'), 'error');
        }
      }

      // relation user_attachment
      if ($uploaded_file_id) {
        $ua = [
          'user_id'     => $userID,
          'file_id'     => $uploaded_file_id,
          'note'        => $dbs->escape_string($desc),
          'input_date'  => date('Y-m-d H:i:s'),
          'last_update' => date('Y-m-d H:i:s')
        ];

        if ($sql_op->insert('user_attachment', $ua)) {
          utility::jsToastr('Attachment', __('Attachment uploaded succesfully!'), 'success');

          echo '<script type="text/javascript">
            try {
              if (parent && typeof parent.setIframeContent === "function") {
                parent.setIframeContent("attachIframe", "'.addslashes($iframe_url).'");
              } else if (parent && parent.document) {
                var ifr = parent.document.getElementById("attachIframe");
                if (ifr) ifr.src = ifr.src;
              }
              if (parent && parent.$ && parent.$.colorbox) parent.$.colorbox.close();
            } catch(e) { console.log(e); }
          </script>';
        } else {
          utility::jsToastr('Attachment', __('Failed to save relation to user_attachment')."\n".$sql_op->error, 'error');
        }
      }
    }
  }

  // reload edit data after submit 
  if ($is_edit) {
    $eq = $dbs->query("
      SELECT ua.id AS relation_id, ua.note,
             f.file_id, f.file_title, f.file_url, f.file_dir, f.file_desc, f.file_name, f.mime_type
      FROM user_attachment ua
      LEFT JOIN files f ON f.file_id = ua.file_id
      WHERE ua.id={$relation_id} AND ua.user_id={$userID} AND ua.file_id={$fileID}
      LIMIT 1
    ");
    $row = $eq ? $eq->fetch_assoc() : null;
    if ($row) $edit_d = array_merge($edit_d, $row);
  }
}


ob_start();

echo '<style>
  body { padding: 12px 14px 90px 14px; }
  .s-table td { vertical-align: top; }
  .s-input { width: 100%; }
  .req { color:#c00; font-weight:bold; }
  .hint { font-style: italic; color:#555; }
</style>';

$title_page = $is_edit ? __('Edit Attachment') : __('Upload Attachment');

echo '<div class="per_title"><h2>'.$title_page.'</h2></div>';
echo '<div class="infoBox">'.__('User').': <b>'.htmlspecialchars($user_name).'</b> (ID: '.(int)$userID.')</div>';

if ($is_edit) {
  echo '<div class="infoBox" style="margin-top:6px;">'
    .__('Editing File').': <b>'.htmlspecialchars($edit_d['file_title'] ?: ($edit_d['file_name'] ?: ('File #'.$fileID))).'</b>'
    .'</div>';
}

$action = $build_url([
  'action' => 'pop_attach_user',
  'userID' => $userID,
  'relation_id' => $relation_id,
  'fileID' => $fileID,
  'block'  => 1
]);

echo '<form method="post" action="'.htmlspecialchars($action).'" enctype="multipart/form-data">';

// hidden wajib plugin
echo '<input type="hidden" name="mod" value="'.htmlspecialchars($mod).'">';
echo '<input type="hidden" name="id" value="'.htmlspecialchars($pid).'">';
if (!empty($sec)) echo '<input type="hidden" name="sec" value="'.htmlspecialchars($sec).'">';
echo '<input type="hidden" name="userID" value="'.(int)$userID.'">';
echo '<input type="hidden" name="relation_id" value="'.(int)$relation_id.'">';
echo '<input type="hidden" name="fileID" value="'.(int)$fileID.'">';

echo '<table id="dataList" class="s-table table" style="width:100%" cellpadding="2" cellspacing="0">';

// Title
echo '<tr>
  <td class="alterCell font-weight-bold" style="width:22%;">'.__('Title').'<span class="req">*</span></td>
  <td class="alterCell2">
    <input class="form-control s-input" type="text" name="fileTitle" required value="'.htmlspecialchars((string)$edit_d['file_title']).'">
  </td>
</tr>';

// Repo dir
echo '<tr>
  <td class="alterCell font-weight-bold">'.__('Repo. Directory').'</td>
  <td class="alterCell2">
    <select class="form-control s-input" name="fileDir">';
foreach ($repodir_options as $opt) {
  $val = (string)$opt[0];
  $lab = (string)$opt[1];
  $sel = ($val === (string)($edit_d['file_dir'] ?? '')) ? ' selected' : '';
  echo '<option value="'.htmlspecialchars($val).'"'.$sel.'>'.htmlspecialchars($lab).'</option>';
}
echo '</select>
  </td>
</tr>';

// File upload
echo '<tr>
  <td class="alterCell font-weight-bold">'.($is_edit ? __('Replace File (optional)') : __('File To Attach')).'</td>
  <td class="alterCell2">';

if ($is_edit && !empty($edit_d['file_name'])) {
  echo '<div style="margin-bottom:6px;">'
    .__('Current').': <b>'.htmlspecialchars($edit_d['file_name']).'</b>'
    .(!empty($edit_d['mime_type']) ? ' <span class="hint">('.htmlspecialchars($edit_d['mime_type']).')</span>' : '')
    .'</div>';
}

echo '<div style="display:flex; gap:10px; align-items:center;">
    <input class="form-control s-input" type="file" name="file2attach" id="file2attach">
    <span style="white-space:nowrap;">'.__('Maximum').' '.$sysconf['max_upload'].' KB</span>
  </div>
  <div class="hint" style="margin-top:6px;">'.__('Leave empty if you only want to update title/URL/description.').'</div>
  </td>
</tr>';

// URL
echo '<tr>
  <td class="alterCell font-weight-bold">'.__('URL').'</td>
  <td class="alterCell2">
    <input class="form-control s-input" type="text" name="fileURL" placeholder="http://..." value="'.htmlspecialchars((string)$edit_d['file_url']).'">
    <div class="hint" style="margin-top:6px;">'.__('Optional: use URL if not uploading a file').'</div>
  </td>
</tr>';

// Desc/Note
$desc_value = $edit_d['note'] !== '' ? $edit_d['note'] : ($edit_d['file_desc'] ?? '');
echo '<tr>
  <td class="alterCell font-weight-bold">'.__('Note/Description').'</td>
  <td class="alterCell2">
    <textarea class="form-control s-input" name="fileDesc" rows="3">'.htmlspecialchars((string)$desc_value).'</textarea>
  </td>
</tr>';

echo '</table>';

$btn_text = $is_edit ? __('Update Attachment') : __('Upload Now');

echo '<div style="margin-top:10px;">
  <button type="submit" name="save" value="1" class="btn btn-primary">'.$btn_text.'</button>
  <button type="button" class="btn btn-default" onclick="try{ if(parent && parent.$ && parent.$.colorbox) parent.$.colorbox.close(); else window.close(); }catch(e){ window.close(); }">'.__('Close').'</button>
</div>';

echo '</form>';

echo '<script>
  try { document.querySelector(\'input[name="fileTitle"]\').focus(); } catch(e){}
</script>';

echo '<style>
  /* PAKSA: kalau blocker ada, jangan nutupin klik */
  #blocker{
    display:none !important;
    opacity:0 !important;
    pointer-events:none !important;
    z-index:-1 !important;
  }
</style>';

echo '<script type="text/javascript">
(function () {
  function nukeBlocker(doc) {
    if (!doc) return;
    try {
      // 1) remove element
      var b = doc.getElementById("blocker");
      if (b && b.parentNode) b.parentNode.removeChild(b);

      // 2) kalau dibuat ulang, minimal dibuat tidak mengganggu
      var bs = doc.querySelectorAll("#blocker");
      bs.forEach(function(x){
        x.style.display = "none";
        x.style.pointerEvents = "none";
        x.style.opacity = "0";
        x.style.zIndex = "-1";
      });

      // 3) beberapa template pakai overlay lain
      var others = doc.querySelectorAll(".blocker, .s-blocker, .overlayBlocker");
      others.forEach(function(x){
        x.style.display = "none";
        x.style.pointerEvents = "none";
        x.style.opacity = "0";
        x.style.zIndex = "-1";
      });
    } catch(e) {}
  }

  function tryAllLevels() {
    // dokumen sekarang (iframe)
    nukeBlocker(document);

    // parent / top (kalau satu origin)
    try { if (window.parent && window.parent.document) nukeBlocker(window.parent.document); } catch(e){}
    try { if (window.parent && window.parent.parent && window.parent.parent.document) nukeBlocker(window.parent.parent.document); } catch(e){}
    try { if (window.top && window.top.document) nukeBlocker(window.top.document); } catch(e){}

    // colorbox overlay 
    try {
      if (window.parent && window.parent.$) {
        window.parent.$("#cboxLoadingOverlay, #cboxLoadingGraphic").hide();
        window.parent.$("#cboxOverlay, #colorbox, #cboxWrapper, #cboxContent, #cboxLoadedContent")
          .css("pointer-events", "auto");
        window.parent.$("#cboxLoadedContent iframe").css("pointer-events", "auto");
      }
    } catch(e){}
  }

  // blocker mode
  tryAllLevels();
  window.addEventListener("load", tryAllLevels);
  setTimeout(tryAllLevels, 50);
  setTimeout(tryAllLevels, 150);
  setTimeout(tryAllLevels, 300);
  setTimeout(tryAllLevels, 700);
  setTimeout(tryAllLevels, 1200);

  // Guard
  function watch(doc) {
    if (!doc || !doc.documentElement) return;
    try {
      var obs = new MutationObserver(function(muts){
        for (var i=0; i<muts.length; i++) {
          if (!muts[i].addedNodes) continue;
          muts[i].addedNodes.forEach(function(n){
            if (n && n.id === "blocker") tryAllLevels();
          });
        }
      });
      obs.observe(doc.documentElement, { childList:true, subtree:true });
    } catch(e) {}
  }

  watch(document);
  try { if (window.parent && window.parent.document) watch(window.parent.document); } catch(e){}
  try { if (window.top && window.top.document) watch(window.top.document); } catch(e){}

  // Fallback terakhir: interval singkat 2 detik, lalu stop
  var t = 0;
  var iv = setInterval(function(){
    tryAllLevels();
    t += 1;
    if (t >= 10) clearInterval(iv); // ~2 detik
  }, 200);
})();
</script>';

$content = ob_get_clean();
require SB.'/admin/'.$sysconf['admin_template']['dir'].'/notemplate_page_tpl.php';
