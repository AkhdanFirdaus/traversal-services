<?php

namespace Engine;

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
}
