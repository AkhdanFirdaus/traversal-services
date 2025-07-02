# Proyek Penelitian Terapan
Deskripsi dan Kerangka Proyek Generator Test Case PHP

## Kelompok 3
- M. Fajri Davyza Chaniago - 23524010
- Ziadan Qowi - 23524019
- Akhdan Musyaffa Firdaus - 23524039
- Yogie Anugrah Ramadhan - 23524049

## Topik
Directory/Path Traversal

## Deskripsi Proyek
Sistem ini adalah sebuah engine komprehensif yang dirancang untuk menganalisis proyek PHP, mendeteksi potensi kerentanan keamanan (dengan fokus awal pada Path Traversal dan Directory Traversal), dan kemudian secara otomatis menghasilkan serta meningkatkan test case menggunakan model kecerdasan buatan (Large Language Model - LLM), Google Gemini. Tujuan utamanya adalah untuk meningkatkan kualitas dan cakupan pengujian keamanan pada aplikasi PHP dengan memanfaatkan kekuatan dari beberapa LLM terkemuka, serta memberikan pemantauan proses secara real-time melalui Socket.IO.

## Fitur Utama:
1. Kloning Repositori: Mengkloning repositori PHP target dari URL Git.
2. Analisis Heuristik: Menganalisis kode sumber secara statis menggunakan Abstract Syntax Tree (AST) dan set aturan berbasis CWE (Common Weakness Enumeration) serta pola kustom untuk mengidentifikasi potensi kerentanan.
3. Mutation Testing (dengan Infection):
- Menjalankan Infection dengan penanganan konfigurasi yang fleksibel (mencari infection.json5 atau infection.json milik proyek target, atau menggunakan template infection.json5.dist dari engine ini).
- Mendapatkan skor MSI (Mutation Score Indicator) awal dan statistik mutasi detail (total, terbunuh, lolos, error, dll.) dengan memprioritaskan parsing dari laporan infection-report.json yang terstruktur, dengan fallback ke log teks jika perlu.
- Mengekstrak detail mutan yang lolos (escaped mutants), termasuk nama mutator, file, baris, deskripsi, kode yang telah dimutasi (replacement), dan upaya untuk mendapatkan snippet kode asli di sekitar lokasi mutasi. Informasi ini sangat berharga sebagai input untuk LLM.
- Menyimpan path ke direktori laporan HTML yang dihasilkan Infection untuk analisis visual.
- Menjalankan ulang Infection setelah test case yang dihasilkan AI ditambahkan untuk mengukur peningkatan MSI.
4. Generasi Test Case dengan Multi-AI (LLM): Mengirimkan informasi tentang kode yang rentan (dan detail mutan yang lolos) ke berbagai LLM (Google Gemini, Anthropic Claude, OpenAI GPT) untuk menghasilkan test case PHPUnit baru yang spesifik.
5. Seleksi dan Ekspor Test Case: Memilih test case terbaik yang dihasilkan AI dan mengekspornya.
6. Pelaporan: Menghasilkan laporan analisis heuristik dan perbandingan skor MSI (sebelum dan sesudah intervensi LLM), termasuk log proses detail dan path ke artefak Infection.
7. Notifikasi Real-time: Mengirimkan pembaruan progres tahapan pipeline, nilai MSI, dan snippet kode melalui Socket.IO.
8. Dua Mode Operasi: Dapat dijalankan melalui Command Line Interface (CLI) dan REST API.
9. Dockerisasi: Proyek dilengkapi dengan Dockerfile dan docker-compose.yml untuk kemudahan deployment dan konsistensi lingkungan.
10. Konfigurasi via .env: Penggunaan file .env untuk manajemen konfigurasi.

## Struktur Direktori Proyek (Skeleton)
```
project-root/
│
├── public/                   # Root untuk akses web (API)
│   └── server.php
│
├── src/                      # Kode sumber utama aplikasi
│   ├── App/
│   │   └── AppService.php      # Layanan inti aplikasi
│   │
│   ├── Pipeline/             # Tahapan dalam pipeline pemrosesan
│   │   ├── AiTestGenerator.php
│   │   └── Reporter.php
│   │   ├── InfectionRunner.php
│   │   ├── PhpUnitRunner.php
│   │   ├── RepositoryCloner.php
│   │
│   └── Utils/                  # Utilitas pendukung
│       ├── ConfigInfection.php
│       ├── ConfigPHPUnit.php
│       ├── FileHelper.php
│       ├── Logger.php          # Log CLI dan Notifikasi via Socket.IO
│       ├── PromptBuilder.php
│       ├── ReportParser.php
│       ├── JsonCleaner.php
│
├── config/                     # File konfigurasi
│   ├── patterns.json           # Pola kerentanan kustom
│   └── infection.json5.dist    # Template konfigurasi Infection (format JSON5)
│
├── tmp/                        # Direktori file sementara
│   ├── clones_cli/
│   ├── clones_api/
│   ├── app_cli.log
│   └── api.log
│
├── outputs/                    # Laporan yang dihasilkan
│   ├── <roomid>/
│
├── vendor/                     # Dependensi Composer
│
├── .env                        # File environment (konfigurasi sensitif, jangan di-commit)
├── env_example                # Contoh file environment
├── composer.json               # Definisi dependensi dan autoloading
├── composer.lock               # Versi dependensi yang terkunci
├── Dockerfile                  # Instruksi build image Docker
├── docker-compose.yml          # Konfigurasi layanan Docker Compose
├── main_cli.yml                # Script utama CLI
├── main_server.yml             # Script utama REST Api server
└── README.md                   # Dokumentasi proyek
```

## Cara Kerja Engine (Pipeline Utama)
Sistem bekerja melalui serangkaian tahapan yang diorkestrasi oleh AppService.php, khususnya dalam metode handleProcessRepo:

### Stage 1: Persiapan
1. Memuat konfigurasi dari .env dan menginisialisasi layanan inti. Setiap proses diberikan taskId unik.
2. Kloning Repositori (RepositoryCloner): Mengkloning repositori Git target. Menjalankan composer install --no-dev dan secara kondisional composer require --dev phpunit/phpunit jika PHPUnit tidak ditemukan.
3. Memastikan file konfigurasi (infection.json5 dan phpunit.xml) ada di target, jika tidak akan menggunakan template yang sudah disediakan pada folder config.

### Stage 2: Membangun Konteks
1. Mengidentifikasi directory project target, menghasilkan file `git-lsfiles-output.txt`.
2. Menjalankan PHPUnit menghasilkan file `phpunit-initial.json`.
3. Menjalankan Infection Awal (InfectionRunner), menghasilkan file `msi-initial.json`.
4. Mendapatkan file `patterns.json` pada folder config sebagai pola vulnerable yang akan dideteksi.
5. Membuat prompt konteks dengan menggabungkan seluruh output step 1-4.


### Stage 3: Generasi Test Case
1. Mengirimkan prompt konteks ke LLM dengan mode multi-turn chat.
2. Melakukan iterasi pada setiap file yang terdeteksi pada `msi-initial.json`.
3. Membangun prompt untuk melakukan generasi Test Case AI (AiTestGenerator) pada file target.
4. Hasil generasi di validasi terlebih dahulu untuk menghindari sintaks error dan kesalahan logika dengan menjalankan phpunit test secara terisolasi.

### Stage 4: Export dan Report
1. Menjalankan infection final, menghasilkan file `msi-final.json`.
2. Melakukan perbandingan `msi-initial.json` dan `msi-final.json`.
3. Membuat report dengan format yang sudah ditentukan.
3. Membuat zip dari hasil generasi test case.