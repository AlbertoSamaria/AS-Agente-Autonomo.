<?php
declare(strict_types=1);
require_once __DIR__.'/../config/config.php';
if(PHP_SAPI!=='cli'){exit("CLI apenas\n");}
$name=$argv[1]??'Administrador';$email=$argv[2]??'admin@local';$pass=$argv[3]??'';
if($pass===''){exit("Uso: php tools/create_admin.php Nome email password\n");}
db()->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')")->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
echo "Administrador criado: {$email}\n";
