<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : user_form.php
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

// privileges
$can_read  = utility::havePrivilege('system', 'r');
$can_write = utility::havePrivilege('system', 'w');

$changecurrent = isset($_GET['changecurrent']) ? true : false;

if (!$can_read) {
  die('<div class="errorBox">'.__('You don\'t have enough privileges to view this section').'</div>');
}

$itemID = (int)($_GET['uid'] ?? 0);
if ($changecurrent) $itemID = (int)$_SESSION['uid'];

if (!$changecurrent && (int)$_SESSION['uid'] !== 1) {
  if ($itemID !== (int)$_SESSION['uid']) {
    die('<div class="errorBox">'.__('You can only edit your own profile. Use "Change Current User Data" to edit your profile.').'</div>');
  }
}

if (!$changecurrent && (int)$_SESSION['uid'] !== 1 && !$can_write) {
  die('<div class="errorBox">'.__('You don\'t have enough privileges to edit users.').'</div>');
}

// plugin identity params
$mod = $_GET['mod'] ?? 'system';
$pid = $_GET['id'] ?? 'user-extended';
$sec = $_GET['sec'] ?? '';

function build_plugin_url($params = array()) {
  global $mod, $pid, $sec;

  $default_params = array(
    'mod' => $mod,
    'id'  => $pid,
  );

  if (!empty($sec)) $default_params['sec'] = $sec;

  $all_params = array_merge($default_params, $params);
  return $_SERVER['PHP_SELF'] . '?' . http_build_query($all_params);
}

function is_ajax_request(): bool {
  // fetch() tidak selalu set X-Requested-With, jadi gunakan flag ajax=1
  if (!empty($_POST['ajax']) && $_POST['ajax'] == '1') return true;
  $hdr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
  return (strtolower($hdr) === 'xmlhttprequest');
}


if (is_ajax_request()) {
  @ini_set('display_errors', '0');
  @ini_set('html_errors', '0');
  if (!ob_get_level()) { ob_start(); }
}

function json_exit($arr) {
  // buang semua output liar yang terlanjur tercetak (progress script, notice, dll)
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr);
  exit;
}

// fetch record
$rec_q = \SLiMS\DB::query('SELECT * FROM user WHERE user_id=?', array($itemID));
$rec_d = $rec_q->first();

//Remove Image

if (isset($_POST['removeImage']) && isset($_POST['uimg']) && isset($_POST['img'])) {

  $user_id    = (int)$_POST['uimg'];
  $image_name = $dbs->escape_string(trim((string)$_POST['img']));

  // balik ke halaman edit yang sama (bukan index)
  $back_url = build_plugin_url([
    'action' => 'detail',
    'uid'    => $changecurrent ? (int)$_SESSION['uid'] : $user_id
  ]);
  if ($changecurrent) $back_url .= '&changecurrent=1';

  // auth: admin atau pemilik akun
  if (!((int)$_SESSION['uid'] === 1 || $user_id === (int)$_SESSION['uid'])) {
    if (is_ajax_request()) json_exit(['ok'=>false,'status'=>'error','message'=>__('Not authorized'),'reload_url'=>$back_url]);
    toastr(__('Not authorized'))->error();
    echo '<script>parent.$("#mainContent").simbioAJAX('.json_encode($back_url).');</script>';
    exit;
  }


  $query_image = $dbs->query("SELECT user_id FROM user WHERE user_id='{$user_id}' AND user_image='{$image_name}' LIMIT 1");
  if (!$query_image || $query_image->num_rows < 1) {
    if (is_ajax_request()) json_exit(['ok'=>false,'status'=>'error','message'=>__('Image not found'),'reload_url'=>$back_url]);
    toastr(__('Image not found'))->error();
    echo '<script>parent.$("#mainContent").simbioAJAX('.json_encode($back_url).');</script>';
    exit;
  }

  $delete = $dbs->query("UPDATE user SET user_image=NULL, last_update='".date('Y-m-d')."' WHERE user_id={$user_id}");
  if ($delete) {


    if ($user_id === (int)$_SESSION['uid']) {
      $_SESSION['upict'] = 'person.png';
    }

    $image_path = rtrim(IMGBS, '/').'/persons/'.$image_name;
    if (is_file($image_path)) @unlink($image_path);

    writeLog('staff', $_SESSION['uid'], 'system',
      $_SESSION['realname'].' remove user image ('.$image_name.')',
      'User Image', 'Remove'
    );

    $msg = __('Image removed successfully');

    if (is_ajax_request()) {
      json_exit([
        'ok' => true,
        'status' => 'success',
        'message' => $msg,
        'reload_url' => $back_url
      ]);
    }

    // non-ajax fallback
    toastr($msg)->success();
    echo '<script>parent.$("#mainContent").simbioAJAX('.json_encode($back_url).');</script>';
    exit;
  }

  if (is_ajax_request()) json_exit(['ok'=>false,'status'=>'error','message'=>__('Failed to remove image'),'reload_url'=>$back_url]);
  toastr(__('Failed to remove image'))->error();
  echo '<script>parent.$("#mainContent").simbioAJAX('.json_encode($back_url).');</script>';
  exit;
}


if (isset($_POST['saveData'])) {

  $userName = ((int)$_SESSION['uid'] > 1)
    ? (string)$_SESSION['uname']
    : trim(strip_tags((string)($_POST['userName'] ?? '')));

  $realName = trim(strip_tags((string)($_POST['realName'] ?? '')));
  $passwd1  = $dbs->escape_string(trim((string)($_POST['passwd1'] ?? '')));
  $passwd2  = $dbs->escape_string(trim((string)($_POST['passwd2'] ?? '')));

  $fail = function($msg) {
    if (is_ajax_request()) json_exit(['ok'=>false,'message'=>$msg]);
    toastr($msg)->error();
    exit;
  };

  if ($userName === '' || $realName === '') $fail(__('User Name or Real Name can\'t be empty'));
  if (($passwd1 && $passwd2) && ($passwd1 !== $passwd2)) $fail(__('Password confirmation does not match.'));
  if (!simbio_form_maker::isTokenValid()) $fail(__('Invalid form submission token!'));

  $data = array();
  $data['username']  = $dbs->escape_string(trim($userName));
  $data['realname']  = $dbs->escape_string(trim($realName));
  $data['user_type'] = (int)($_POST['userType'] ?? 0);
  $data['email']     = $dbs->escape_string(trim((string)($_POST['eMail'] ?? '')));

  // NEW FIELDS
  $data['nip']         = $dbs->escape_string(trim((string)($_POST['nip'] ?? '')));
  $data['phone']       = $dbs->escape_string(trim((string)($_POST['phone'] ?? '')));
  $data['address']     = $dbs->escape_string(trim((string)($_POST['address'] ?? '')));
  $data['pangkat']     = $dbs->escape_string(trim((string)($_POST['pangkat'] ?? '')));
  $data['golongan']    = $dbs->escape_string(trim((string)($_POST['golongan'] ?? '')));
  $data['birth_place'] = $dbs->escape_string(trim((string)($_POST['birth_place'] ?? '')));

  $data['birth_date']      = (!empty($_POST['birth_date'])) ? $dbs->escape_string((string)$_POST['birth_date']) : 'literal{NULL}';
  $data['pustakawan_date'] = (!empty($_POST['pustakawan_date'])) ? $dbs->escape_string((string)$_POST['pustakawan_date']) : 'literal{NULL}';

  // social
  $social_media = array();
  if (isset($_POST['social']) && is_array($_POST['social'])) {
    foreach ($_POST['social'] as $id => $val) {
      $val = $dbs->escape_string(trim((string)$val));
      if ($val !== '') $social_media[$id] = $val;
    }
  }
  $data['social_media'] = $social_media ? $dbs->escape_string(serialize($social_media)) : 'literal{NULL}';

  // groups 
  if (!$changecurrent && isset($_POST['noChangeGroup'])) {
    $groups = (!empty($_POST['groups']) && is_array($_POST['groups'])) ? serialize($_POST['groups']) : 'literal{NULL}';
    $data['groups'] = trim((string)$groups);
  }

  // password
  if (($passwd1 && $passwd2) && ($passwd1 === $passwd2)) {
    if ($changecurrent) {
      $old = $dbs->escape_string(trim((string)($_POST['old_passwd'] ?? '')));
      $up_q = $dbs->query('SELECT passwd FROM user WHERE user_id='.(int)$_SESSION['uid']);
      $up_d = $up_q ? $up_q->fetch_row() : null;
      if (!$up_d || !password_verify($old, $up_d[0])) $fail(__('Password change failed.'));
    }
    $data['passwd'] = password_hash($passwd2, PASSWORD_BCRYPT);
  }

  $data['input_date']   = date('Y-m-d');
  $data['last_update']  = date('Y-m-d');

  // photo upload
  $upload_status = null;
  $upload_error  = '';
  $uploaded_name = '';

  if (!empty($_FILES['image']) && !empty($_FILES['image']['name']) && (int)($_FILES['image']['size'] ?? 0) > 0) {
    $upload = new simbio_file_upload();
    $upload->setAllowableFormat($sysconf['allowed_images']);
    $upload->setMaxSize(((int)$sysconf['max_image_upload']) * 1024);
    $upload->setUploadDir(rtrim(IMGBS, '/').'/persons/');


    if (method_exists($upload, 'setReportProgress')) $upload->setReportProgress(false);
    if (property_exists($upload, 'report_progress')) $upload->report_progress = false;
    if (property_exists($upload, 'show_progress'))   $upload->show_progress = false;

    $safe_user = preg_replace('~[^a-z0-9_]+~', '_', strtolower(str_replace(array(',', '.', ' ', '-'), '_', $data['username'])));
    $new_filename = 'user_' . $safe_user . '_' . date('YmdHis') . '_' . rand(1000, 9999);

    $upload_status = $upload->doUpload('image', $new_filename);

    if ($upload_status == UPLOAD_SUCCESS) {
      $uploaded_name = $upload->new_filename;
      $data['user_image'] = $dbs->escape_string($uploaded_name);

      writeLog('staff', $_SESSION['uid'], 'system',
        $_SESSION['realname'].' upload user image ('.$uploaded_name.')',
        'User Image', 'Upload'
      );
    } else {
      $upload_error = $upload->error;

      writeLog('staff', $_SESSION['uid'], 'system',
        'FAILED: '.$_SESSION['realname'].' upload user image - '.$upload_error,
        'User Image', 'Failed'
      );
    }
  }

  $sql_op = new simbio_dbop($dbs);

  $redirect_url = build_plugin_url(['action' => 'index']);

  $is_update = !empty($_POST['updateRecordID']);
  if ($is_update) {
    unset($data['input_date']);
    $uid = $changecurrent ? (int)$_SESSION['uid'] : (int)$_POST['updateRecordID'];

    $ok = $sql_op->update('user', $data, 'user_id='.$uid);

    if ($ok) {
      writeLog('staff', $_SESSION['uid'], 'system',
        $_SESSION['realname'].' update user extended ('.$data['realname'].')',
        'UserExtended', 'Update'
      );

      if ($upload_status === UPLOAD_SUCCESS && ($changecurrent || $uid == (int)$_SESSION['uid'])) {
        $_SESSION['upict'] = $uploaded_name ?: ($_SESSION['upict'] ?? 'person.png');
      }

      if (is_ajax_request()) {
        json_exit([
          'ok' => true,
          'message' => __('User Data Successfully Updated'),
          'redirect_url' => $redirect_url,
          'upload' => [
            'attempted' => ($upload_status !== null),
            'ok' => ($upload_status === UPLOAD_SUCCESS),
            'error' => $upload_error,
            'filename' => $uploaded_name
          ]
        ]);
      }

      toastr(__('User Data Successfully Updated'))->success();
      echo '<script type="text/javascript">parent.$("#mainContent").simbioAJAX("'.$redirect_url.'");</script>';
      exit;
    }

    $err = __('FAILED to update')."\n".$sql_op->error;
    $fail($err);
  }

  // INSERT
  if ($sql_op->insert('user', $data)) {
    $new_id = $sql_op->insert_id;

    writeLog('staff', $_SESSION['uid'], 'system',
      $_SESSION['realname'].' add user extended ('.$data['realname'].')',
      'UserExtended', 'Add'
    );

    if (is_ajax_request()) {
      json_exit([
        'ok' => true,
        'message' => __('New User Data Successfully Saved'),
        'redirect_url' => $redirect_url,
        'upload' => [
          'attempted' => ($upload_status !== null),
          'ok' => ($upload_status === UPLOAD_SUCCESS),
          'error' => $upload_error,
          'filename' => $uploaded_name
        ]
      ]);
    }

    toastr(__('New User Data Successfully Saved'))->success();
    echo '<script type="text/javascript">parent.$("#mainContent").simbioAJAX("'.$redirect_url.'");</script>';
    exit;
  }

  $err = __('FAILED to save')."\n".$sql_op->error;
  $fail($err);
}

// Form
$form_action = build_plugin_url(
  ($rec_q->count() > 0)
    ? ['action' => 'detail', 'uid' => $itemID]
    : ['action' => 'detail']
);

$form = new simbio_form_table_AJAX('mainForm', $form_action, 'post');

if (property_exists($form, 'form_attr')) {
  $form->form_attr = 'enctype="multipart/form-data" id="mainForm"';
}

$form->submit_button_attr   = 'name="saveData" value="'.__('Save').'" class="btn btn-default btn-save-user"';
$form->table_attr           = 'id="dataList" class="s-table table"';
$form->table_header_attr    = 'class="alterCell font-weight-bold"';
$form->table_content_attr   = 'class="alterCell2"';

$form->addHidden('mod', $mod);
$form->addHidden('id',  $pid);
if (!empty($sec)) $form->addHidden('sec', $sec);

if ($rec_q->count() > 0) {
  $form->edit_mode = true;
  $form->record_title = $rec_d['realname'];
  $form->submit_button_attr = 'name="saveData" value="'.__('Update').'" class="btn btn-default btn-save-user"';
  $form->addHidden('updateRecordID', $itemID);
}

// username
if ((int)$_SESSION['uid'] > 1) {
  $form->addAnything(__('Login Username'), '<strong>'.htmlspecialchars($rec_d['username'] ?? '').'</strong>');
} else {
  $form->addTextField('text', 'userName', __('Login Username').'*', $rec_d['username'] ?? '', 'style="width:50%;" class="form-control"');
}

$form->addTextField('text', 'realName', __('Real Name').'*', $rec_d['realname'] ?? '', 'style="width:50%;" class="form-control"');

$utype_options = array();
foreach ($sysconf['system_user_type'] as $id => $name) $utype_options[] = array($id, $name);
$form->addSelectList('userType', __('User Type').'*', $utype_options, $rec_d['user_type'] ?? '', 'class="form-control col-3"');

$form->addTextField('text', 'eMail', __('E-Mail'), $rec_d['email'] ?? '', 'style="width:50%;" class="form-control"');

// NEW FIELDS
$form->addTextField('text', 'nip', 'NIP', $rec_d['nip'] ?? '', 'style="width:50%;" class="form-control"');
$form->addTextField('text', 'phone', __('Phone'), $rec_d['phone'] ?? '', 'style="width:50%;" class="form-control"');
$form->addTextField('textarea', 'address', __('Address'), $rec_d['address'] ?? '', 'rows="2" style="width:70%;" class="form-control"');
$form->addTextField('text', 'pangkat', 'Jabatan', $rec_d['pangkat'] ?? '', 'style="width:50%;" class="form-control"');
$form->addTextField('text', 'golongan', 'Golongan', $rec_d['golongan'] ?? '', 'style="width:30%;" class="form-control"');
$form->addTextField('text', 'birth_place', 'Tempat Lahir', $rec_d['birth_place'] ?? '', 'style="width:50%;" class="form-control"');
$form->addTextField('date', 'birth_date', 'Tanggal Lahir', $rec_d['birth_date'] ?? '', 'class="form-control col-12"');
$form->addTextField('date', 'pustakawan_date', 'Tgl Penetapan Status Pustakawan', $rec_d['pustakawan_date'] ?? '', 'class="form-control col-12"');

// social media
$social_media = array();
if (!empty($rec_d['social_media'])) $social_media = @unserialize($rec_d['social_media']);

$str_input = '<div class="row">';
foreach ($sysconf['social'] as $id => $social) {
  $val = isset($social_media[$id]) ? $social_media[$id] : '';
  $str_input .= '<div class="social-input col-4"><span class="social-form"><input type="text" name="social['.$id.']" value="'.htmlspecialchars($val).'" placeholder="'.htmlspecialchars($social).'" class="form-control" /></span></div>';
}
$str_input .= '</div>';
$form->addAnything(__('Social Media'), $str_input);

// PHOTO SECTION
$str_photo = '';
if (!empty($rec_d['user_image'])) {
  $image_path = SWB.'images/persons/'.urlencode($rec_d['user_image']);
  $str_photo .= '<div id="imageFilename" class="mb-2">';
  $str_photo .= '<a href="'.$image_path.'" class="openPopUp notAJAX" target="_blank">';
  $str_photo .= '<strong>'.htmlspecialchars($rec_d['user_image']).'</strong>';
  $str_photo .= '</a>';

  if ((int)$_SESSION['uid'] == 1 || $itemID == (int)$_SESSION['uid']) {
    $str_photo .= ' <a href="javascript:void(0)" onclick="removeUserImage('.$itemID.', \''.htmlspecialchars($rec_d['user_image']).'\')" class="btn btn-sm btn-danger ml-2">'.__('Remove').'</a>';
  }
  $str_photo .= '</div>';

  $full_image_path = rtrim(IMGBS, '/').'/persons/'.$rec_d['user_image'];
  if (file_exists($full_image_path)) {
    $str_photo .= '<div class="mb-3">';
    $str_photo .= '<img src="'.$image_path.'" alt="User Photo" style="max-width:150px; max-height:150px; border:1px solid #ddd; padding:3px;" />';
    $str_photo .= '</div>';
  }
}

$str_photo .= '<div class="custom-file">';
$str_photo .= '<input type="file" name="image" id="image" class="custom-file-input" accept="';
if (isset($sysconf['allowed_images']) && is_array($sysconf['allowed_images'])) {
  $str_photo .= implode(',', $sysconf['allowed_images']);
} elseif (isset($sysconf['allowed_images'])) {
  $str_photo .= $sysconf['allowed_images'];
}
$str_photo .= '" />';
$str_photo .= '<label class="custom-file-label" for="image">'.__('Choose new photo').'</label>';
$str_photo .= '</div>';

$str_photo .= '<small class="text-muted d-block mt-1">';
$str_photo .= __('Maximum').' '.$sysconf['max_image_upload'].' KB';
$str_photo .= '<br>'.__('Allowed formats:').' ';
if (isset($sysconf['allowed_images']) && is_array($sysconf['allowed_images'])) {
  $str_photo .= implode(', ', $sysconf['allowed_images']);
} elseif (isset($sysconf['allowed_images'])) {
  $str_photo .= $sysconf['allowed_images'];
}
$str_photo .= '</small>';

$form->addAnything(__('User Photo'), $str_photo);

// groups (admin only)
if (!$changecurrent && (int)$_SESSION['uid'] == 1) {
  $form->addHidden('noChangeGroup', '1');
  $gq = $dbs->query('SELECT group_id, group_name FROM user_group WHERE group_id != 1');
  $opts = array();
  while ($gd = $gq->fetch_row()) $opts[] = array($gd[0], $gd[1]);
  $form->addCheckBox('groups', __('Group(s)'), $opts, !empty($rec_d['groups']) ? unserialize($rec_d['groups']) : null);
}

// password
if ($changecurrent) $form->addTextField('password', 'old_passwd', __('Old Password').'*', '', 'style="width:50%;" class="form-control"');
$form->addTextField('password', 'passwd1', __('New Password').'*', '', 'style="width:50%;" class="form-control"');
$form->addTextField('password', 'passwd2', __('Confirm New Password').'*', '', 'style="width:50%;" class="form-control"');

// ATTACHMENT SECTION
$uid_for_attach = (int)$itemID;
$visibility = ($uid_for_attach > 0 && $rec_q->count() > 0) ? '' : 'd-none';

$pop_url = build_plugin_url([
  'action' => 'pop_attach_user',
  'userID' => $uid_for_attach,
  'block'  => 1
]);

$iframe_src = build_plugin_url([
  'action' => 'iframe_attach_user',
  'userID' => $uid_for_attach,
  'block'  => 1
]);

$attach_html  = '<div class="'.$visibility.' s-margin__bottom-1">';
$attach_html .= '<a id="btnAddUserAttachment" class="s-btn btn btn-default notAJAX" '
  . 'href="'.htmlspecialchars($pop_url).'" data-width="780" data-height="520" '
  . 'title="'.__('Upload Attachment').'">'.__('Add Attachment').'</a>';
$attach_html .= '</div>';

$attach_html .= '<div class="attachment-frame-container" style="border:1px solid #ddd; border-radius:4px; padding:10px; margin-top:10px;">';

$attach_html .= '<iframe name="attachIframe" id="attachIframe" style="width:100%; height:240px; border:none;" src="' . htmlspecialchars($iframe_src) . '"></iframe>';
$attach_html .= '</div>';

$attach_html .= '
<script type="text/javascript">
(function(){
  // refresh iframe list (pakai cache buster biar langsung update)
  window.refreshAttachmentIframe = function(){
    try {
      var ifr = document.getElementById("attachIframe");
      if (!ifr) return;
      var base = ifr.getAttribute("data-base-src") || ifr.src;
      if (!ifr.getAttribute("data-base-src")) ifr.setAttribute("data-base-src", base.split("&_=").join("").split("?_=").join(""));
      var clean = ifr.getAttribute("data-base-src");
      var sep = (clean.indexOf("?") >= 0) ? "&" : "?";
      ifr.src = clean + sep + "_=" + Date.now();
    } catch(e){ console.log(e); }
  };

  // open modal colorbox iframe 
  window.openUserAttachmentModal = function(url, w, h){
    if (!window.jQuery || !jQuery.colorbox) {
      // fallback terakhir kalau colorbox bener-bener ga ada
      window.open(url, "_blank", "width="+(w||780)+",height="+(h||520)+",scrollbars=yes,resizable=yes");
      return;
    }
    jQuery.colorbox({
      href: url,
      iframe: true,
      width: (w||780),
      height: (h||520),
      overlayClose: false,
      onComplete: function(){
        try{
          jQuery("#cboxLoadingOverlay,#cboxLoadingGraphic").hide();
          jQuery("#cboxOverlay").css("pointer-events","none");
          jQuery("#colorbox,#cboxWrapper,#cboxContent,#cboxLoadedContent").css("pointer-events","auto");
          jQuery("#cboxLoadedContent iframe").css("pointer-events","auto");
          jQuery("#cboxLoadedContent").css("overflow","auto");
          // bar/frame colorbox jangan nyolong klik
          jQuery("#cboxTopLeft,#cboxTopCenter,#cboxTopRight,#cboxMiddleLeft,#cboxMiddleRight,#cboxBottomLeft,#cboxBottomCenter,#cboxBottomRight")
            .css("pointer-events","none");
        }catch(e){}
      },
      onClosed: function(){
        try{ jQuery("#cboxOverlay").css("pointer-events","auto"); }catch(e){}
      }
    });
  };

  // tombol Add Attachment
  jQuery(document).off("click", "#btnAddUserAttachment").on("click", "#btnAddUserAttachment", function(e){
    e.preventDefault();
    e.stopImmediatePropagation();
    window.openUserAttachmentModal(this.href, jQuery(this).data("width"), jQuery(this).data("height"));
    return false;
  });
})();
</script>
';

$form->addAnything('Data Diri Pegawai', $attach_html);


echo '<div class="per_title"><h2>'.__('User Extended Form').'</h2></div>';
if ($form->edit_mode) {
  echo '<div class="infoBox">'.__('Editing').': <b>'.htmlspecialchars($rec_d['realname']).'</b> | '.__('Last Update').': '.htmlspecialchars($rec_d['last_update']).'</div>';
}
echo $form->printOut();
?>

<script>
// helper: fetch -> text -> JSON.parse
async function fetchJsonOrThrow(url, options) {
  const r = await fetch(url, options);
  const text = await r.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error('Non-JSON response (HTTP ' + r.status + '):\n' + text.substring(0, 900));
    throw new Error('Non-JSON response (HTTP ' + r.status + ')');
  }
}

// ===== Attachment popup =====
function openAttachmentPopup(userId) {
  if (!userId || userId < 1) {
    alert('<?php echo __('Invalid User ID'); ?>');
    return false;
  }

  var url = '<?php echo $_SERVER['PHP_SELF']; ?>?' +
    'mod=<?php echo urlencode($mod); ?>&' +
    'id=<?php echo urlencode($pid); ?>&' +
    'action=pop_attach_user&' +
    'userID=' + userId;

  var popup = window.open(url, 'attachPopup',
    'width=780,height=500,scrollbars=yes,resizable=yes,menubar=no,toolbar=no,location=no,status=no');

  if (popup) popup.focus();
  else alert('<?php echo __('Popup blocked! Please allow popups for this site.'); ?>');

  return false;
}

function refreshAttachmentIframe() {
  var iframe = document.getElementById('attachIframe');
  if (iframe) iframe.src = iframe.src;
}

// ===== remove image (AJAX) =====
function removeUserImage(userId, imageName) {
  if (!confirm('<?php echo __("Are you sure want to remove this image?"); ?>')) return;

  var formData = new FormData();
  formData.append('removeImage', '1');
  formData.append('uimg', userId);
  formData.append('img', imageName);

  // paksa ajax json
  formData.append('ajax', '1');

  // syarat plugin
  formData.append('mod', '<?php echo addslashes($mod); ?>');
  formData.append('id',  '<?php echo addslashes($pid); ?>');
  <?php if (!empty($sec)) { ?>
  formData.append('sec', '<?php echo addslashes($sec); ?>');
  <?php } ?>

  var url = (document.getElementById('mainForm') && document.getElementById('mainForm').action)
    ? document.getElementById('mainForm').action
    : window.location.href;

  fetchJsonOrThrow(url, { method: 'POST', body: formData, credentials: 'same-origin' })
    .then(function(data) {
      if (data && data.ok) {
        if (window.toastr) toastr.success(data.message || 'OK');

        // INI kuncinya: jangan location.reload() (bisa mental ke index.php)
        var go = (data && data.reload_url) ? data.reload_url : window.location.href;

        if (window.$ && $("#mainContent").length) {
          $("#mainContent").simbioAJAX(go);
        } else {
          location.href = go;
        }
      } else {
        var msg = (data && data.message) ? data.message : 'Failed';
        if (window.toastr) toastr.error(msg); else alert(msg);

        // tetap stay di halaman edit
        if (data && data.reload_url && window.$ && $("#mainContent").length) {
          $("#mainContent").simbioAJAX(data.reload_url);
        }
      }
    })
    .catch(function(err) {
      console.error('Remove image error:', err);
      var msg = 'Remove image gagal: server balas bukan JSON. Lihat Console.';
      if (window.toastr) toastr.error(msg); else alert(msg);
    });
}


// ===================== Delegated events =====================
(function() {
  if (!window.jQuery) {
    console.error('jQuery not found.');
    return;
  }

  // tampilkan nama file
  $(document).off('change.userext', '#image').on('change.userext', '#image', function() {
    var fileName = (this.files && this.files[0]) ? this.files[0].name : '<?php echo addslashes(__("Choose new photo")); ?>';
    var $label = $('label[for="image"]');
    if ($label.length) $label.text(fileName);
  });

  // simpan tombol submit yang diklik (karena kadang ada 2 tombol)
  $(document).off('click.userext', '#mainForm input[name="saveData"]').on('click.userext', '#mainForm input[name="saveData"]', function() {
    $('#mainForm').data('clickedSaveValue', this.value);
  });

  // submit via fetch 
  $(document).off('submit.userext', '#mainForm').on('submit.userext', '#mainForm', function(e) {
    e.preventDefault();

    var form = this;
    form.setAttribute('enctype', 'multipart/form-data');

    var clickedValue = $(form).data('clickedSaveValue') || 'Update';
    var fd = new FormData(form);

    // paksa ajax json
    fd.set('ajax', '1');

    // submit button
    fd.set('saveData', clickedValue);

    // disable semua tombol submit
    var $btns = $(form).find('input[name="saveData"], button[name="saveData"]');
    $btns.prop('disabled', true).addClass('disabled');

    fetchJsonOrThrow(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
    .then(function(res) {
      $btns.prop('disabled', false).removeClass('disabled');

      if (!res || !res.ok) {
        var msg = (res && res.message) ? res.message : 'Update failed';
        if (window.toastr) toastr.error(msg); else alert(msg);
        return;
      }

      if (window.toastr) toastr.success(res.message || 'OK');

      // balik ke list
      var go = res.redirect_url || null;
      if (go && window.$ && $("#mainContent").length) {
        $("#mainContent").simbioAJAX(go);
      } else {
        location.reload();
      }
    })
    .catch(function(err) {
      console.error('Submit error:', err);
      $btns.prop('disabled', false).removeClass('disabled');

      var msg = 'Server response bukan JSON. Console sudah menampilkan awal response.';
      if (window.toastr) toastr.error(msg); else alert(msg);
    });
  });

  // Auto-resize iframe
  var iframe = document.getElementById('attachIframe');
  if (iframe) {
    iframe.onload = function() {
      try {
        var height = iframe.contentWindow.document.body.scrollHeight;
        iframe.style.height = Math.min(Math.max(height, 80), 400) + 'px';
      } catch(e) {
        console.log('Cannot resize iframe:', e);
      }
    };
  }
})();
</script>
