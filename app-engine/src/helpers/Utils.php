<?php

namespace App\Helpers;

class Utils {
    public static function getCurrentTimestamp(): string {
        return date('Y-m-d H:i:s');
    }

    public static function getCurrentDate(): string {
        return date('Y-m-d');
    }

    public static function getCurrentTime(): string {
        return date('H:i:s');
    }
    
    public static function cloneRepo(string $url): void {
        $targetDir = './workspace/repo';
        if (is_dir($targetDir)) {
            exec("rm -rf $targetDir");
        }
        exec("git clone $url $targetDir");
    }

    public static function log(string $stepName, $content): string {
        $reportDir = './logs/engine';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }
        $filePath = "$reportDir/{$stepName}.json";
        file_put_contents($filePath, json_encode($content, JSON_PRETTY_PRINT));
        echo "Report saved to $filePath\n";
        return $filePath;
    }

    public static function zipper(): string {
        // $timestamp = time();
        // $pathName = './workspace/reports';
        // Buat temporary folder
        // zip dan simpan di temporary folder (expire 1 jam)
        // Pastikan dapat diakses secara publik via wget/curl
        // exec("zip -r traversal-$timestamp.zip $pathName");

        $timestamp = time();
        $reportDir = realpath('./workspace/reports');

        if (!$reportDir || !is_dir($reportDir)) {
            return 'Report directory not found';
        }

        // Buat direktori temporary menggunakan mktemp
        $tempDir = trim(shell_exec("mktemp -d /tmp/reports_XXXXXX"));

        // Nama file ZIP
        $zipFile = "$tempDir/reports_$timestamp.zip";

        // Buat file ZIP dari isi direktori report
        exec("zip -r $zipFile $reportDir");

        // Jadwalkan penghapusan file zip dan direktori setelah 1 jam
        $deleteCmd = "echo 'rm -rf $zipFile $tempDir' | at now + 1 hour";
        exec($deleteCmd);

        // Buat file bisa diakses publik (misalnya symlink ke folder public)
        $publicLink = realpath('./public/tmp') . "/reports_$timestamp.zip";
        symlink($zipFile, $publicLink);

        // Return URL untuk diakses publik (misalnya via HTTP)
        return "/tmp/reports_$timestamp.zip";
    }
}
