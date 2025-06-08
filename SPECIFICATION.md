Deskripsi dan Kerangka Proyek Generator Test Case PHP

## Deskripsi Proyek
Sistem ini adalah sebuah engine komprehensif yang dirancang untuk menganalisis proyek PHP, mendeteksi potensi kerentanan keamanan (dengan fokus awal pada Path Traversal dan Directory Traversal), dan kemudian secara otomatis menghasilkan serta meningkatkan test case menggunakan berbagai model kecerdasan buatan (Large Language Model - LLM), termasuk Google Gemini, Anthropic Claude, dan OpenAI GPT. Tujuan utamanya adalah untuk meningkatkan kualitas dan cakupan pengujian keamanan pada aplikasi PHP dengan memanfaatkan kekuatan dari beberapa LLM terkemuka, serta memberikan pemantauan proses secara real-time melalui Socket.IO.

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
├── bin/                      # Skrip CLI
│   └── run.php
│
├── public/                   # Root untuk akses web (API)
│   └── server.php
│
├── src/                      # Kode sumber utama aplikasi
│   ├── App/
│   │   └── AppService.php      # Layanan inti aplikasi
│   │
│   ├── Pipeline/             # Tahapan dalam pipeline pemrosesan
│   │   ├── RepositoryCloner.php
│   │   ├── HeuristicAnalyzer.php
│   │   ├── InfectionRunner.php   # Telah diperbarui secara signifikan
│   │   ├── AiTestGenerator.php
│   │   ├── TestSelector.php
│   │   └── Exporter.php
│   │
│   ├── AST/                    # Komponen terkait Abstract Syntax Tree
│   │   ├── TraversalVisitor.php
│   │   ├── HeuristicRule.php
│   │   ├── VulnerabilityLocation.php
│   │   └── Rules/                # Implementasi aturan heuristik
│   │       ├── CWE22_DirectUserInputInSinkRule.php
│   │       ├── CWE22_ConcatenatedPathWithUserInputRule.php
│   │       └── GenericPatternRule.php
│   │
│   └── Utils/                  # Utilitas pendukung
│       ├── FileHelper.php
│       ├── Logger.php            # Logger PSR-3 compliant
│       ├── PatternLoader.php
│       └── SocketNotifier.php    # Notifikasi via Socket.IO
│
├── config/                   # File konfigurasi
│   ├── patterns.json           # Pola kerentanan kustom
│   └── infection.json5.dist    # Template konfigurasi Infection (format JSON5)
│
├── tmp/                        # Direktori file sementara
│   ├── clones_cli/
│   ├── clones_api/
│   ├── app_cli.log
│   └── api.log
│
├── reports/                    # Laporan yang dihasilkan
│   ├── heuristic_analysis/
│   ├── msi_reports/
│   └── exported_test_cases_cli/
│   └── exported_test_cases_api/
│
├── vendor/                     # Dependensi Composer
│
├── .env                        # File environment (konfigurasi sensitif, jangan di-commit)
├── .env.example                # Contoh file environment
├── composer.json               # Definisi dependensi dan autoloading
├── composer.lock               # Versi dependensi yang terkunci
├── Dockerfile                  # Instruksi build image Docker
├── docker-compose.yml          # Konfigurasi layanan Docker Compose
└── README.md                   # Dokumentasi proyek
```

## Cara Kerja Engine (Pipeline Utama)
Sistem bekerja melalui serangkaian tahapan yang diorkestrasi oleh AppService.php, khususnya dalam metode handleProcessRepo:

1. Inisialisasi: Memuat konfigurasi dari .env dan menginisialisasi layanan inti. Setiap proses diberikan taskId unik.
2. Kloning Repositori (RepositoryCloner): Mengkloning repositori Git target. Menjalankan composer install --no-dev dan secara kondisional composer require --dev phpunit/phpunit jika PHPUnit tidak ditemukan.
3. Analisis Heuristik (HeuristicAnalyzer): Menganalisis kode PHP menggunakan AST dan aturan.
4. Infection Awal (InfectionRunner):
- InfectionRunner memastikan file konfigurasi (infection.json5 atau infection.json) ada di - proyek target, menggunakan template config/infection.json5.dist jika perlu. Konfigurasi - ini diharapkan mendefinisikan output log standar (JSON, HTML, text, summary).
- Menjalankan Infection.
- Hasil (statistik MSI, path ke laporan HTML, dan detail mutan yang lolos termasuk replacement dan original code snippet dari infection-report.json) dicatat dan dikirim via Socket.IO.
5. Generasi Test Case AI (AiTestGenerator): Untuk setiap kerentanan (dan mungkin berdasarkan mutan yang lolos), prompt dikirim ke LLM yang terkonfigurasi. Hasil tes dari masing-masing LLM dikumpulkan.
6. Integrasi Tes AI & Infection Akhir: Tes AI disimpan ke proyek yang di-clone. composer dump-autoload dijalankan. InfectionRunner dijalankan kembali. Skor MSI akhir dicatat dan dibandingkan.
7. Seleksi Tes (TestSelector): Memilih tes AI terbaik.
8. Ekspor Tes (Exporter): Tes terpilih diekspor.
9. Pelaporan: Laporan analisis heuristik dan laporan perbandingan MSI disimpan.
10. Pembersihan: Direktori kloning dihapus.

11. Notifikasi Akhir: Pesan penyelesaian dikirim via Socket.IO.

## Requirements
- PHP 8.2
- Infection dan PHPunit
- Elephantio/Elephant.IO sebagai socket.io client
- vlucas/phpdotenv untuk membaca file .env
- guzzlehttp/guzzle sebagai http client untuk generate ke LLM
- nikic/php-parser sebagai dependensi dari Infection, namun akan digunakan juga untuk AST parser