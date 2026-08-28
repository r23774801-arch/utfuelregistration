# UT Fuel Monitoring System

UT Fuel Monitoring System adalah aplikasi web berbasis PHP dan MySQL untuk mencatat serta memantau pengisian bahan bakar kendaraan/unit operasional. Operator dapat membuka formulir melalui QR Code, memasukkan identitas karyawan dan unit, mencatat jumlah liter serta flow meter, lalu mengunggah foto bukti. Admin dapat memantau data melalui dashboard, riwayat, dan laporan.

## Fitur utama

- Form pengisian BBM yang ramah perangkat seluler (`user.php`).
- Pencarian otomatis data karyawan berdasarkan NRP dan data kendaraan/unit.
- Validasi unit aktif, nilai liter/flow meter, dan nomor lambung.
- Dua foto wajib: dokumentasi pengisian dan foto flow meter.
- Dashboard ringkasan pemakaian harian/bulanan, jumlah transaksi, dan unit aktif.
- Riwayat pengisian dengan filter tanggal, kategori, pencarian, dan pagination.
- Laporan dengan filter tanggal, area, tipe unit, serta ekspor Excel dan PDF.
- QR Code yang mengarah langsung ke formulir operator.
- Salinan transaksi otomatis ke CSV lokal di `exports/fuel_registration.csv`.
- Integrasi opsional dengan Google Sheets dan notifikasi email SMTP.
- Profil admin dan perubahan password.
- Tema terang/gelap serta sesi login dengan batas tidak aktif 10 menit.

## Alur penggunaan

1. Admin login dan membuka menu **QR Fuel**.
2. QR dicetak atau ditampilkan kepada operator.
3. Operator memindai QR dan mengisi NRP, data unit, jumlah liter, flow meter, serta dua foto bukti.
4. Aplikasi memvalidasi data lalu menyimpannya ke tabel `fuel_logs`.
5. Data juga ditambahkan ke CSV lokal. Jika dikonfigurasi, aplikasi mencoba mengirimnya ke Google Sheets dan email admin.
6. Admin memeriksa hasilnya melalui Dashboard, Riwayat Pengisian, atau Laporan.

Kegagalan sinkronisasi Google Sheets atau email tidak membatalkan data yang sudah berhasil disimpan di database dan CSV lokal.

## Teknologi

- PHP 8+ dengan PDO
- MySQL/MariaDB
- Apache (konfigurasi keamanan melalui `.htaccess`)
- HTML, CSS, dan JavaScript tanpa framework backend
- Chart.js, Lucide Icons, dan QR dari QuickChart melalui CDN/layanan eksternal

## Struktur proyek

```text
solar/
├── api/                    # Endpoint pencarian unit, karyawan, dan control terakhir
├── assets/                 # CSS dan JavaScript tema/tampilan
├── docs/                   # Google Apps Script untuk integrasi Sheets
├── exports/                # CSV lokal hasil transaksi (dibuat otomatis)
├── img/                    # Logo dan gambar halaman login
├── includes/               # Koneksi DB, autentikasi, konfigurasi, media, notifikasi
├── migrations/             # Migrasi data referensi karyawan dan unit
├── uploads/                # Foto dokumentasi transaksi
├── dashboard.php           # Dashboard admin
├── laporan.php             # Filter dan ekspor laporan
├── login.php               # Login admin
├── profil.php              # Profil dan perubahan password
├── qr_fuel.php             # QR menuju formulir operator
├── riwayat.php             # Riwayat transaksi
├── setup_admin.php         # Pembuatan admin pertama
└── user.php                # Form registrasi pengisian BBM
```

## Persyaratan

- PHP 8.0 atau lebih baru dengan ekstensi `pdo_mysql`, `fileinfo`, dan `openssl`.
- MySQL atau MariaDB.
- Apache dengan `mod_headers` dan dukungan `.htaccess` (direkomendasikan).
- Folder `uploads/` dan `exports/` dapat ditulis oleh proses web server.
- Akses internet diperlukan untuk ikon, grafik, pembuatan QR, Google Sheets, dan SMTP eksternal.

## Instalasi lokal dengan XAMPP

1. Letakkan proyek di folder web XAMPP, misalnya `C:\xampp\htdocs\solar`.
2. Jalankan Apache dan MySQL dari XAMPP Control Panel.
3. Buat database bernama `ut_fuel_monitoring`.
4. Siapkan skema dasar yang berisi tabel `users`, `unit_categories`, `sites`, `units`, dan `fuel_logs` beserta relasinya.
5. Import `migrations/20260828_secure_reference_data.sql` untuk membuat tabel `employees`, menambahkan `area_location`, dan mengisi data referensi karyawan/unit. Migrasi ini bersifat idempotent, tetapi mengasumsikan tabel dasar pada langkah sebelumnya sudah tersedia.
6. Salin `.env.example` menjadi `.env`, kemudian sesuaikan nilainya.
7. Buka `http://localhost/solar/`. Jika belum ada admin dengan password yang valid, aplikasi akan mengarahkan ke `setup_admin.php`.
8. Isi akun admin pertama, kemudian login.

> Catatan: repositori saat ini menyediakan migrasi data referensi, bukan dump lengkap untuk membuat seluruh skema dari database kosong. Gunakan dump skema awal proyek sebelum menjalankan migrasi tersebut.

## Konfigurasi `.env`

```dotenv
APP_BASE_URL=http://localhost/solar
ALLOW_ADMIN_SETUP=false

DB_HOST=localhost
DB_NAME=ut_fuel_monitoring
DB_USER=root
DB_PASS=

GOOGLE_SHEET_WEBHOOK_URL=
GOOGLE_SHEET_SECRET=

SMTP_ENABLED=false
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=
SMTP_FROM_NAME=Fuel Monitoring
ADMIN_EMAIL=
```

Keterangan penting:

- `APP_BASE_URL`: URL publik aplikasi, digunakan untuk membuat tautan foto yang benar.
- `ALLOW_ADMIN_SETUP`: biarkan `false` setelah admin dibuat. Di server non-localhost, ubah ke `true` hanya sementara ketika setup pertama benar-benar diperlukan.
- `ADMIN_EMAIL`: tujuan notifikasi. Jika kosong, aplikasi memakai email pengguna pertama di database.
- `SMTP_PASSWORD`: untuk Gmail, gunakan App Password dan bukan password akun biasa.
- Jangan commit atau membagikan `.env` karena berisi kredensial.

## Integrasi Google Sheets

1. Buat Google Spreadsheet baru.
2. Buka **Extensions > Apps Script**.
3. Salin isi `docs/google_apps_script.gs` ke editor Apps Script.
4. Ganti `SECRET_TOKEN` dengan token acak yang kuat.
5. Deploy sebagai **Web app** dan atur akses sesuai kebutuhan operasional.
6. Masukkan URL deployment ke `GOOGLE_SHEET_WEBHOOK_URL`.
7. Isi `GOOGLE_SHEET_SECRET` dengan nilai yang sama seperti `SECRET_TOKEN`.

Jangan menggunakan token contoh bawaan untuk lingkungan produksi.

## Halaman dan akses

| URL | Fungsi | Login admin |
|---|---|:---:|
| `user.php` | Form pengisian untuk operator | Tidak |
| `login.php` | Login admin | Tidak |
| `setup_admin.php` | Setup akun admin pertama | Tidak, tetapi dibatasi kondisi setup |
| `dashboard.php` | Ringkasan statistik | Ya |
| `riwayat.php` | Riwayat dan filter transaksi | Ya |
| `laporan.php` | Laporan serta ekspor Excel/PDF | Ya |
| `profil.php` | Profil dan password admin | Ya |
| `qr_fuel.php` | QR formulir operator | Ya |

## Keamanan dan operasional

- Query database menggunakan PDO prepared statements pada input dinamis.
- Form sensitif memakai token CSRF dan password disimpan dengan hash bcrypt.
- Cookie sesi memakai `HttpOnly`, `SameSite=Lax`, dan `Secure` saat HTTPS aktif.
- File PHP tidak diizinkan dijalankan dari folder `uploads/`.
- Validasi unggahan menerima JPG, PNG, atau WEBP dengan ukuran maksimal 3 MB per foto.
- Gunakan HTTPS pada server produksi dan pastikan `.htaccess` aktif.
- Cadangkan database, folder `uploads/`, dan CSV dalam `exports/` secara berkala.

## Troubleshooting singkat

- **Koneksi database gagal:** periksa `DB_HOST`, `DB_NAME`, `DB_USER`, dan `DB_PASS` di `.env`.
- **Setup admin mendapat 403:** akses melalui localhost atau aktifkan `ALLOW_ADMIN_SETUP=true` sementara, lalu kembalikan ke `false`.
- **Foto tidak tampil:** periksa izin tulis folder `uploads/` dan pastikan `APP_BASE_URL` sesuai domain/path aplikasi.
- **Google Sheets tidak terisi:** periksa URL deployment, kesamaan secret, izin Web App, dan koneksi keluar server.
- **Email tidak terkirim:** periksa pengaturan SMTP, App Password, alamat pengirim, dan dukungan koneksi keluar port 587.
- **QR tidak muncul:** QuickChart membutuhkan koneksi internet; tautan form tetap dapat dibuka atau dibagikan secara langsung.

## Zona waktu

Waktu transaksi pada formulir menggunakan zona waktu `Asia/Makassar` (WITA).

