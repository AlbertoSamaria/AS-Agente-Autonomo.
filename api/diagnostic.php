<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
header('Content-Type: text/plain; charset=utf-8');
echo "SA ".SA_VERSION."\n";
try { db()->query("SELECT 1"); echo "MySQL: OK\n"; } catch(Throwable $e) { echo "MySQL: ERRO - ".$e->getMessage()."\n"; }
echo "PHP: ".PHP_VERSION."\n";
