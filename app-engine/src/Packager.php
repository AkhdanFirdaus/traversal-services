<?php

namespace App;

use App\Helpers\Utils;

class Packager {
    public function __construct(private string $dir, private string $outputZip) {}

    public function run(): void {
        $zip = new \ZipArchive();
        $zip->open($this->outputZip, \ZipArchive::CREATE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir));
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($this->dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();

        Utils::log("Packager", "Packaged files into $this->outputZip");
    }
}
