# Proyek Penelitian Terapan

## Kelompok 3
- M. Fajri Davyza Chaniago - 23524010
- Ziadan Qowi - 23524019
- Akhdan Musyaffa Firdaus - 23524039
- Yogie Anugrah Ramadhan - 23524049

## Topic
Directory/Path Traversal

## Engine Flow
> [Git Clone] 
>
>    ↓
>
> [Static Code Analyzer] 
>
>    ↓
>
> [Detect Directory Traversal Risks] 
>
>    ↓
>
> [Mutate Vulnerable Code] 
>
>    ↓
>
> [Generate Secure Test Case] 
>
>    ↓
>
> [Run Mutation Testing]
>
>    ↓
>
> [Calculate Score + Generate Report]

## Usage
1. `docker compose build`
2. `docker compose run engine composer dump-autoload`
3. `docker compose run engine php run.php https://<git-repo>.git`
4. untuk cleaning data `rm -rf workspace/* build/ infection-log.txt`

## Vulnerability repo samples
1. https://github.com/opsxcq/exploit-CVE-2016-10033.git