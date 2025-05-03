<?php

function cloneRepo(string $url): void {
    $targetDir = './workspace/repo';
    if (is_dir($targetDir)) {
        exec("rm -rf $targetDir");
    }
    exec("git clone $url $targetDir");
}
