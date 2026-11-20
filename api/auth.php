<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/auth/Auth.php';
require_once dirname(__DIR__).'/services/security/RateLimiter.php';

Auth::start();
$action=$_GET['action']??'me';
try {
    if($action==='register'){
        require_post();
        if(!(new RateLimiter())->allow('register',5,3600,RateLimiter::identity())) {
            json_response(['success'=>false,'error'=>'Foram feitas demasiadas tentativas de registo. Tenta novamente dentro de uma hora.'],429);
        }

        $d=json_decode(file_get_contents('php://input'),true);
        if(!is_array($d)) json_response(['success'=>false,'error'=>'Pedido inválido.'],400);

        $name=trim((string)($d['name']??''));
        $email=strtolower(trim((string)($d['email']??'')));
        $password=(string)($d['password']??'');
        $confirmation=(string)($d['password_confirmation']??'');

        if($name==='' || mb_strlen($name)>120) {
            json_response(['success'=>false,'error'=>'Indica um nome válido (até 120 caracteres).'],422);
        }
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) {
            json_response(['success'=>false,'error'=>'Indica um endereço de email válido.'],422);
        }
        if(strlen($password)<10 || strlen($password)>72) {
            json_response(['success'=>false,'error'=>'A palavra-passe deve ter entre 10 e 72 bytes.'],422);
        }
        if(!hash_equals($password,$confirmation)) {
            json_response(['success'=>false,'error'=>'As palavras-passe não coincidem.'],422);
        }

        $pdo=db();
        try {
            $pdo->beginTransaction();
            $statement=$pdo->prepare(
                "INSERT INTO users (name,email,password_hash,role,active) VALUES (?,?,?,'viewer',1)"
            );
            $statement->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $userId=(int)$pdo->lastInsertId();
            $pdo->commit();
        } catch(PDOException $exception) {
            if($pdo->inTransaction()) $pdo->rollBack();
            if((string)$exception->getCode()==='23000') {
                json_response(['success'=>false,'error'=>'Este email já está associado a uma conta.'],409);
            }
            throw $exception;
        }

        if(!Auth::login($email,$password)) {
            json_response(['success'=>false,'error'=>'A conta foi criada, mas não foi possível iniciar sessão. Usa o login para entrar.'],500);
        }
        Auth::audit('account.registered',['email'=>$email],$userId);
        json_response(['success'=>true,'user'=>Auth::user()],201);
    }

    if($action==='login'){
        require_post();
        if(!(new RateLimiter())->allow('login',10,900,RateLimiter::identity()))json_response(['success'=>false,'error'=>'Demasiadas tentativas. Tenta novamente mais tarde.'],429);
        $d=json_decode(file_get_contents('php://input'),true)?:[];
        if(Auth::login(trim((string)($d['email']??'')),(string)($d['password']??''))) json_response(['success'=>true,'user'=>Auth::user()]);
        json_response(['success'=>false,'error'=>'Credenciais inválidas'],401);
    }
    if($action==='logout'){ Auth::requireCsrf(); Auth::logout(); json_response(['success'=>true]); }
    if($action==='me'){ json_response(['success'=>true,'user'=>Auth::user()]); }
    json_response(['success'=>false,'error'=>'Ação inválida'],400);
} catch(Throwable $e){ json_response(['success'=>false,'error'=>$e->getMessage()],500); }
