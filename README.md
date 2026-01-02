# User Extended (SLiMS Plugin)

Plugin **User Extended** untuk **SLiMS v9.7.2 (Bulian)** yang menambahkan form data pegawai + manajemen lampiran (attachments) berbasis **iframe + Colorbox (modal)** dengan tampilan mengikuti **style native SLiMS**.

---

## Author

- **Name:** Erwan Setyo Budi  
- **Email:** erwans818@gmail.com


## Fitur Utama

1. **User Extended Form**
   - Menambahkan/menampilkan field tambahan pada data user (contoh: NIP, phone, address, pangkat, golongan, tempat & tanggal lahir, tgl penetapan pustakawan, social media, dll).
   - Mendukung mode **admin** dan **non-admin** (non-admin hanya bisa edit profil sendiri).

2. **User Photo (user_image)**
   - Upload foto user ke folder `images/persons/`.
   - Tombol **Remove Photo** menggunakan **AJAX** (tetap di halaman edit, tidak terpental ke index).
   - Menampilkan notifikasi (toastr) saat berhasil/gagal.

3. **User Attachments**
   - Daftar lampiran tampil dalam **iframe** di halaman edit user.
   - Tambah lampiran menggunakan **Colorbox iframe modal** (bukan window baru).
   - Setelah upload/update/delete berhasil, daftar attachments **langsung refresh otomatis** (tanpa klik tombol Update user).
   - Lampiran punya tombol:
     - **View/Preview**
     - **Edit** (title/url/desc + optional replace file)
     - **Delete** (dengan konfirmasi)
   - Semua action plugin menjaga parameter wajib: `mod` dan `id`.

---

## Struktur Tabel yang Digunakan

### Tabel `user`
Kolom foto menggunakan: `user.user_image` (varchar(250) NULL)

### Tabel `files`
Memakai tabel bawaan SLiMS untuk metadata file.

### Tabel `user_attachment`
Relasi user ↔ file, minimal:
- `id` (PK)
- `user_id`
- `file_id`
- `note`
- `input_date`
- `last_update`

> Pastikan tabel `user_attachment` sudah ada (atau dibuat oleh plugin/SQL migrasi).

---

## Kebutuhan Sistem

- PHP **8.1.x**
- SLiMS **v9.7.2 (Bulian)**
- Admin template default SLiMS (menggunakan `notemplate_page_tpl.php`)
- jQuery + Colorbox aktif di halaman admin (bawaan SLiMS)

---

## Instalasi

1. **Copy folder plugin**
   - Letakkan folder plugin ke:
     ```
     slims9/plugins/user-extended/
     ```

2. **Pastikan database**
   - Pastikan tabel `user_attachment` tersedia.
   - Pastikan tabel `files` bawaan SLiMS ada (default SLiMS).

3. **Aktifkan plugin**
   - Masuk Admin SLiMS → **System → Plugins** (atau menu plugin manager)
   - Aktifkan **User Extended**

4. **Cek akses**
   - User harus punya privilege `system` (read/write) sesuai kebutuhan.

---

##  Cara Pakai

### 1) User List
Masuk menu **User Extended → User List**
- Admin dapat melihat list user dan edit.
- Non-admin hanya bisa edit profil sendiri (atau via “Change Current User Data”).

### 2) Edit User
Di halaman edit user:
- Bagian **User Photo**:
  - Upload foto baru
  - Remove foto via tombol remove (AJAX)

- Bagian **User Attachments**:
  - Klik **Add Attachment** → muncul modal iframe
  - Setelah upload sukses → modal menutup → iframe daftar langsung refresh
  - Tombol **Edit** untuk setiap lampiran → modal edit → update → refresh otomatis
  - Tombol **Delete** → konfirmasi → refresh otomatis

---

## Screen Shoot
<img width="1366" height="1544" alt="image" src="https://github.com/user-attachments/assets/37a913b1-5c80-43ac-8fcd-f5262333ff31" />



