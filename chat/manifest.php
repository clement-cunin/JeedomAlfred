<?php
header('Content-Type: application/manifest+json');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/manifest.json');
