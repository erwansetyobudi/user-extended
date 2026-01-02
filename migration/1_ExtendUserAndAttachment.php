<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 02/01/2026 16:40
 * @File name           : 1_ExtendUserAndAttachment.php
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

require_once __DIR__ . '/../../../sysconfig.inc.php';

class ExtendUserAndAttachment
{
    public function up()
    {
        global $dbs;

        // Tambah kolom (jika belum ada)
        $cols = [];
        $q = $dbs->query("SHOW COLUMNS FROM `user`");
        while ($r = $q->fetch_assoc()) $cols[$r['Field']] = true;

        $adds = [];
        if (!isset($cols['nip']))            $adds[] = "ADD COLUMN `nip` VARCHAR(30) NULL AFTER `realname`";
        if (!isset($cols['phone']))          $adds[] = "ADD COLUMN `phone` VARCHAR(30) NULL AFTER `email`";
        if (!isset($cols['address']))        $adds[] = "ADD COLUMN `address` TEXT NULL AFTER `phone`";
        if (!isset($cols['pangkat']))        $adds[] = "ADD COLUMN `pangkat` VARCHAR(50) NULL AFTER `address`";
        if (!isset($cols['golongan']))       $adds[] = "ADD COLUMN `golongan` VARCHAR(20) NULL AFTER `pangkat`";
        if (!isset($cols['birth_date']))     $adds[] = "ADD COLUMN `birth_date` DATE NULL AFTER `golongan`";
        if (!isset($cols['birth_place']))    $adds[] = "ADD COLUMN `birth_place` VARCHAR(100) NULL AFTER `birth_date`";
        if (!isset($cols['pustakawan_date']))$adds[] = "ADD COLUMN `pustakawan_date` DATE NULL AFTER `birth_place`";

        if (!empty($adds)) {
            $sql = "ALTER TABLE `user` " . implode(", ", $adds);
            $dbs->query($sql);
        }

        // index NIP (cek dulu)
        $idx = [];
        $q2 = $dbs->query("SHOW INDEX FROM `user`");
        while ($r2 = $q2->fetch_assoc()) $idx[$r2['Key_name']] = true;
        if (!isset($idx['idx_user_nip'])) {
            $dbs->query("ALTER TABLE `user` ADD KEY `idx_user_nip` (`nip`)");
        }

        // table attachment
        $dbs->query("CREATE TABLE IF NOT EXISTS `user_attachment` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `file_id` INT(11) NOT NULL,
          `note` VARCHAR(255) NULL,
          `input_date` DATETIME NULL,
          `last_update` DATETIME NULL,
          PRIMARY KEY (`id`),
          KEY `idx_user_attach_user` (`user_id`),
          KEY `idx_user_attach_file` (`file_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
    }

    public function down()
    {
        global $dbs;

        $dbs->query("DROP TABLE IF EXISTS `user_attachment`");

        // Drop kolom kalau ada
        $cols = [];
        $q = $dbs->query("SHOW COLUMNS FROM `user`");
        while ($r = $q->fetch_assoc()) $cols[$r['Field']] = true;

        $drops = [];
        foreach (['nip','phone','address','pangkat','golongan','birth_date','birth_place','pustakawan_date'] as $c) {
            if (isset($cols[$c])) $drops[] = "DROP COLUMN `$c`";
        }
        if (!empty($drops)) {
            $dbs->query("ALTER TABLE `user` " . implode(", ", $drops));
        }
    }
}
