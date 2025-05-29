# Sistem Generator Test Case PHP Otomatis

## 1. Deskripsi Proyek

Sistem ini adalah sebuah *engine* yang dirancang untuk menganalisis proyek PHP, mendeteksi potensi kerentanan keamanan (khususnya *Path Traversal* dan *Directory Traversal*), dan kemudian secara otomatis menghasilkan serta meningkatkan *test case* menggunakan kecerdasan buatan (Large Language Model - LLM). Tujuannya adalah untuk meningkatkan kualitas dan cakupan pengujian keamanan pada aplikasi PHP.

Fitur Utama:

* **Kloning Repositori**: Mengkloning repositori PHP target dari URL Git.
* **Analisis Heuristik**: Menganalisis kode sumber secara statis menggunakan Abstract Syntax Tree (AST) dan set aturan berbasis CWE (Common Weakness Enumeration) serta pola kustom untuk mengidentifikasi potensi kerentanan.
* **Mutation Testing (dengan Infection)**:
    * Menjalankan Infection untuk mendapatkan skor MSI (Mutation Score Indicator) awal.
    * Menjalankan ulang Infection setelah *test case* yang dihasilkan AI ditambahkan untuk mengukur peningkatan MSI.
* **Generasi Test Case dengan AI (LLM)**: Mengirimkan informasi tentang kode yang rentan dan/atau mutan yang selamat ke LLM untuk menghasilkan *test case* PHPUnit baru yang spesifik.
* **Seleksi dan Ekspor Test Case**: Memilih *test case* terbaik yang dihasilkan AI (berdasarkan kriteria tertentu, misal peningkatan MSI atau jumlah mutan yang terbunuh) dan mengekspornya.
* **Pelaporan**: Menghasilkan laporan analisis heuristik dan perbandingan skor MSI.
* **Dua Mode Operasi**: Dapat dijalankan melalui Command Line Interface (CLI) dan REST API.

## 2. Struktur Direktori (Skeleton Proyek)

Berikut adalah struktur direktori dan file utama proyek:

```
project-root/
│
├── bin/                      # Skrip CLI sebagai titik masuk aplikasi
│   └── run.php               # Skrip utama untuk menjalankan pipeline via CLI
│
├── public/                   # Direktori root untuk akses web (jika menggunakan server web)
│   └── server.php            # Titik masuk untuk panggilan REST API
│
├── src/                      # Direktori utama berisi semua logika inti aplikasi
│   │
│   ├── App/                  # Kelas layanan utama yang mengorkestrasi logika aplikasi
│   │   └── AppService.php
│   │
│   ├── Pipeline/             # Kelas-kelas yang merepresentasikan setiap tahap dalam pipeline proses
│   │   ├── RepositoryCloner.php
│   │   ├── HeuristicAnalyzer.php
│   │   ├── InfectionRunner.php
│   │   ├── AiTestGenerator.php
│   │   ├── TestSelector.php
│   │   └── Exporter.php
│   │
│   ├── AST/                    # Komponen terkait Abstract Syntax Tree (AST) untuk analisis kode
│   │   ├── TraversalVisitor.php
│   │   ├── HeuristicRule.php         # Interface untuk aturan heuristik
│   │   ├── VulnerabilityLocation.php # DTO untuk detail kerentanan
│   │   └── Rules/                    # Implementasi konkret dari HeuristicRule
│   │       ├── CWE22_DirectUserInputInSinkRule.php
│   │       ├── CWE22_ConcatenatedPathWithUserInputRule.php
│   │       └── GenericPatternRule.php
│   │
│   └── Utils/                  # Kelas-kelas utilitas pendukung
│       ├── FileHelper.php
│       ├── Logger.php
│       └── PatternLoader.php
│
├── config/                   # File-file konfigurasi aplikasi
│   ├── patterns.json           # File JSON berisi definisi pola kerentanan
│   └── infection.json.dist     # Contoh konfigurasi default untuk Infection
│
├── tmp/                        # Direktori untuk file-file sementara (kloning repo, log sementara)
│   ├── clones_cli/
│   ├── clones_api/
│   └── app_cli.log
│   └── api.log
│
├── reports/                    # Direktori untuk output laporan akhir
│   ├── heuristic_analysis/
│   ├── msi_reports/            # Laporan perbandingan MSI sebelum & sesudah LLM
│   └── exported_test_cases_cli/
│   └── exported_test_cases_api/
│
├── vendor/                     # Dependensi yang dikelola oleh Composer
│
├── .env                        # File environment (jangan di-commit, berisi konfigurasi sensitif)
├── .env.example                # Contoh file environment
├── composer.json               # Mendefinisikan dependensi proyek dan autoloading
├── composer.lock               # Mencatat versi pasti dari dependensi
└── README.md                   # File ini
```

## 3. Cara Kerja Engine (Pipeline Utama)

Sistem bekerja melalui serangkaian tahapan yang diorkestrasi, terutama dalam metode `handleProcessRepo` di `AppService.php`:

1.  **Inisialisasi**:
    * Memuat variabel environment dari file `.env` (menggunakan `phpdotenv`).
    * Menginisialisasi komponen inti seperti `Logger`, `PatternLoader`, `HttpClient`, dan `AppService` itu sendiri.

2.  **Kloning Repositori (`RepositoryCloner`)**:
    * Menerima URL repositori Git sebagai input.
    * Mengkloning repositori ke direktori sementara di dalam `tmp/clones_[cli|api]/`.
    * Menjalankan `composer install --no-dev --ignore-platform-reqs` di dalam repositori yang di-clone untuk memastikan dependensi proyek target terinstal (ini penting agar Infection dan tes yang dihasilkan dapat berjalan dengan benar).

3.  **Analisis Heuristik (`HeuristicAnalyzer`)**:
    * Menganalisis semua file PHP di dalam direktori sumber proyek yang di-clone (biasanya `src/` atau `app/`, atau root).
    * Menggunakan `nikic/php-parser` untuk membuat Abstract Syntax Tree (AST) dari setiap file.
    * `TraversalVisitor` akan menjelajahi AST dan menerapkan serangkaian `HeuristicRule` (termasuk aturan dari `config/patterns.json` yang dimuat oleh `PatternLoader` dan diinstansiasi sebagai `GenericPatternRule`).
    * Potensi kerentanan yang ditemukan dicatat sebagai objek `VulnerabilityLocation`.
    * Hasil analisis disimpan dalam laporan JSON di direktori `reports/heuristic_analysis/`.

4.  **Menjalankan Infection Awal (`InfectionRunner`)**:
    * Mencoba menemukan *executable* Infection (di *vendor* proyek yang di-clone atau global).
    * Memastikan file konfigurasi `infection.json` ada di proyek yang di-clone (jika tidak, salin dari *template* atau buat *default*).
    * Menjalankan Infection pada proyek yang di-clone. Opsi `--logger-json` digunakan untuk menghasilkan laporan JSON yang detail, dan nama file log dibuat unik (misalnya, `initial_infection_report.json`).
    * Skor MSI (Mutation Score Indicator) dan Covered MSI awal diparsing dari laporan Infection.
    * Hasil ini dicatat dalam laporan MSI gabungan.

5.  **Generasi Test Case dengan AI (`AiTestGenerator`)**:
    * Jika ada kerentanan yang ditemukan oleh analisis heuristik dan kunci API LLM dikonfigurasi:
        * Untuk setiap kerentanan, sebuah *prompt* detail dibuat. *Prompt* ini berisi informasi tentang kerentanan (CWE, file, baris kode, *snippet* kode, *sink function*, input yang rentan) dan meminta LLM untuk membuat *test case* PHPUnit yang spesifik untuk mengeksploitasi kerentanan tersebut.
        * Permintaan dikirim ke API LLM (misalnya, Google Gemini API) menggunakan `GuzzleHttp\Client`.
        * Kode *test case* yang dihasilkan oleh LLM diekstrak dari respons.
        * Data *test case* yang dihasilkan (kode, informasi kerentanan sumber, dll.) dikumpulkan.

6.  **Integrasi Test Case AI dan Menjalankan Infection Akhir**:
    * **Langkah Kritis (Implementasi Detail Diperlukan)**: *Test case* yang dihasilkan AI perlu:
        * Disimpan ke file yang benar dalam struktur direktori tes proyek yang di-clone (misalnya, `tests/AiGenerated/`).
        * Memastikan *namespace*, nama kelas, dan struktur tes sesuai dengan konvensi proyek target dan PHPUnit.
        * Memastikan *autoloader* proyek yang di-clone dapat menemukan tes baru (mungkin perlu `composer dump-autoload`).
        * Konfigurasi PHPUnit (`phpunit.xml`) mungkin perlu diperbarui atau dirancang untuk secara otomatis menyertakan tes dari direktori baru.
    * Setelah tes AI (diasumsikan) terintegrasi, `InfectionRunner` dijalankan kembali dengan nama file log yang berbeda (misalnya, `final_infection_report.json`).
    * Skor MSI dan Covered MSI akhir (setelah tes AI) diparsing.
    * Hasil ini dicatat dalam laporan MSI gabungan, dan peningkatan MSI dihitung.

7.  **Seleksi Test Case Terbaik (`TestSelector`)**:
    * Berdasarkan metrik tertentu (misalnya, peningkatan MSI yang disebabkan oleh tes, jumlah mutan baru yang dibunuh - memerlukan analisis laporan Infection yang lebih detail), tes AI "terbaik" dipilih. Implementasi saat ini masih dasar.

8.  **Ekspor Test Case (`Exporter`)**:
    * *Test case* AI yang terpilih diekspor, biasanya dalam format arsip ZIP, ke direktori `reports/exported_test_cases_[cli|api]/`.

9.  **Pelaporan MSI Gabungan**:
    * Sebuah file laporan JSON unik (misalnya, `msi_report_[nama_repo]_[timestamp].json`) dibuat di `reports/msi_reports/`. Laporan ini berisi:
        * URL repositori dan *timestamp*.
        * Detail laporan MSI awal (skor, path log).
        * Detail laporan MSI akhir (skor, path log, jumlah tes AI yang diterapkan).
        * Peningkatan MSI.
        * Log proses keseluruhan.

10. **Pembersihan (`RepositoryCloner::cleanup`)**:
    * Direktori tempat repositori di-clone akan dihapus.

## 4. Penggunaan

### A. Prasyarat

* PHP >= 8.2
* Composer
* Git
* (Opsional, untuk menjalankan Infection secara manual jika tidak terinstal global) Infection (`infection/infection`)
* Kunci API untuk layanan LLM yang digunakan (misalnya, Google Gemini), diatur dalam variabel environment `LLM_API_KEY`.

### B. Instalasi

1.  Clone repositori proyek ini.
2.  Salin `.env.example` menjadi `.env` dan sesuaikan nilainya:
    ```bash
    cp .env.example .env
    nano .env # atau editor teks lainnya
    ```
    Pastikan untuk mengisi `LLM_API_KEY` dan path lainnya jika perlu.
3.  Jalankan `composer install` untuk menginstal semua dependensi PHP.

### C. Menjalankan via Command Line Interface (CLI)

Skrip utama untuk CLI adalah `bin/run.php`.

* **Menganalisis satu file PHP secara heuristik:**
    ```bash
    php bin/run.php analyze-file --path=/path/ke/file/anda.php
    ```
    Hasilnya akan ditampilkan di konsol, dan laporan JSON akan disimpan di direktori `reports/heuristic_analysis/`.

* **Memproses repositori secara penuh:**
    ```bash
    php bin/run.php process-repo --url=[https://github.com/contoh/proyek-php.git](https://github.com/contoh/proyek-php.git)
    ```
    Opsi tambahan:
    * `--branch=<nama_branch>`: Untuk menentukan *branch* spesifik yang akan di-clone.
    * `--infection-opts="--min-msi=60 --threads=4"`: Untuk meneruskan opsi tambahan ke Infection (pastikan menggunakan tanda kutip jika ada spasi).

    Proses ini akan menjalankan semua tahapan pipeline dan menghasilkan berbagai laporan di direktori `reports/`.

### D. Menjalankan via REST API

Sistem juga menyediakan *endpoint* API sederhana melalui `public/server.php`. Anda dapat menjalankannya menggunakan server PHP bawaan (untuk pengembangan):

1.  Mulai server dari direktori *root* proyek:
    ```bash
    composer serve
    ```
    Atau secara manual:
    ```bash
    php -S localhost:8080 -t public public/server.php
    ```
    Server akan berjalan di `http://localhost:8080`.

2.  Kirim request `POST` ke endpoint `http://localhost:8080/api/analyze` dengan *body* JSON.
    * **Header**: `Content-Type: application/json`

    * **Body JSON untuk menganalisis file**:
        ```json
        {
            "actionType": "analyze-file",
            "path": "/path/absolut/yang/aman/dan/diizinkan/untuk/dianalisis.php"
        }
        ```
        **PERINGATAN KEAMANAN**: Untuk `analyze-file` via API, pastikan path yang diterima sangat divalidasi dan terbatas pada direktori yang aman. Jangan pernah mengizinkan path file arbitrer dari input pengguna API tanpa sanitasi dan pembatasan yang ketat.

    * **Body JSON untuk memproses repositori**:
        ```json
        {
            "actionType": "process-repo",
            "url": "[https://github.com/contoh/proyek-php.git](https://github.com/contoh/proyek-php.git)",
            "branch": "main", // opsional
            "infection-opts": "--min-msi=50" // opsional
        }
        ```
        **PERINGATAN PERFORMA**: Operasi `process-repo` bisa sangat lama. Untuk penggunaan API produksi, sangat disarankan untuk menjalankan tugas ini secara asinkron (misalnya, menggunakan *job queue*) daripada request HTTP sinkron yang bisa *timeout*.

### E. Melihat Laporan

Semua laporan (analisis heuristik, perbandingan MSI, *test case* yang diekspor) akan disimpan dalam direktori `reports/` dengan subdirektori yang sesuai.

## 5. Pengembangan dan Kontribusi

* **Unit Tests**: (Belum ada dalam kerangka ini, tetapi direkomendasikan) Jalankan dengan `composer test` jika ada.
* **Infection pada Proyek Ini Sendiri**: Jalankan `composer infection` untuk menguji kualitas tes dari sistem generator ini sendiri.
* **Kontribusi**: Silakan buat *issue* atau *pull request* jika Anda menemukan bug atau ingin menambahkan fitur.

---

Dokumentasi ini bertujuan untuk memberikan panduan awal. Detail implementasi spesifik, terutama terkait integrasi tes AI dan analisis laporan Infection yang mendalam, mungkin memerlukan penyesuaian dan pengembangan lebih lanjut.
