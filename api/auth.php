<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/auth/Auth.php';
require_once dirname(__DIR__).'/services/security/RateLimiter.php';
require_once dirname(__DIR__).'/services/auth/AccountTokenService.php';
require_once dirname(__DIR__).'/services/auth/AccountMailer.php';

Auth::start();
$action=$_GET['action']??'me';

function auth_payload(): array
{
    $data=json_decode(file_get_contents('php://input'),true);
    return is_array($data)?$data:[];
}

try {
    if($action==='register'){
        require_post();
        if(!(new RateLimiter())->allow('register',5,3600,RateLimiter::identity())) {
            json_response(['success'=>false,'error'=>'Foram feitas demasiadas tentativas de registo. Tenta novamente dentro de uma hora.'],429);
        }

        $d=auth_payload();
        $name=trim((string)($d['name']??''));
        $email=strtolower(trim((string)($d['email']??'')));
        $password=(string)($d['password']??'');
        $confirmation=(string)($d['password_confirmation']??'');

        if($name==='' || mb_strlen($name)>120) json_response(['success'=>false,'error'=>'Indica um nome válido (até 120 caracteres).'],422);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) json_response(['success'=>false,'error'=>'Indica um endereço de email válido.'],422);
        if(strlen($password)<10 || strlen($password)>72) json_response(['success'=>false,'error'=>'A palavra-passe deve ter entre 10 e 72 bytes.'],422);
        if(!hash_equals($password,$confirmation)) json_response(['success'=>false,'error'=>'As palavras-passe não coincidem.'],422);

        $pdo=db();
        try {
            $pdo->beginTransaction();
            $statement=$pdo->prepare(
                "INSERT INTO users (name,email,password_hash,role,active,email_verified_at) VALUES (?,?,?,'viewer',1,NULL)"
            );
            $statement->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $userId=(int)$pdo->lastInsertId();
            $token=AccountTokenService::issue($pdo,$userId,'verify_email',24);
            $pdo->commit();
        } catch(PDOException $exception) {
            if($pdo->inTransaction()) $pdo->rollBack();
            if((string)$exception->getCode()==='23000') json_response(['success'=>false,'error'=>'Este email já está associado a uma conta.'],409);
            throw $exception;
        }

        $sent=false;
        try { $sent=AccountMailer::sendVerification($email,$name,$token); }
        catch(Throwable $mailError) { error_log('SA verification email: '.$mailError->getMessage()); }

        Auth::audit('account.registered',['email'=>$email,'verification_email_sent'=>$sent],$userId);
        json_response([
            'success'=>true,
            'requires_verification'=>true,
            'email_sent'=>$sent,
            'message'=>$sent
                ? 'Conta criada. Enviámos um link de confirmação para o teu e-mail. Confirma-o antes de iniciar sessão.'
                : 'A conta foi criada, mas não foi possível enviar o e-mail. Verifica a configuração de envio ou tenta reenviar a confirmação.',
        ],201);
    }

    if($action==='resend_verification'){
        require_post();
        if(!(new RateLimiter())->allow('resend_verification',3,3600,RateLimiter::identity())) {
            json_response(['success'=>false,'error'=>'Aguarda antes de pedir outro e-mail de confirmação.'],429);
        }
        $email=strtolower(trim((string)(auth_payload()['email']??'')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            $stmt=db()->prepare('SELECT id,name,email FROM users WHERE email=? AND email_verified_at IS NULL AND active=1 LIMIT 1');
            $stmt->execute([$email]);
            $user=$stmt->fetch();
            if($user){
                $token=AccountTokenService::issue(db(),(int)$user['id'],'verify_email',24);
                try {
                    if(!AccountMailer::sendVerification($user['email'],$user['name'],$token)) {
                        error_log('SA could not send verification email.');
                    }
                } catch(Throwable $mailError) { error_log('SA verification email: '.$mailError->getMessage()); }
            }
        }
        json_response(['success'=>true,'message'=>'Se existir uma conta por confirmar com esse e-mail, enviaremos um novo link.']);
    }

    if($action==='request_reset'){
        require_post();
        if(!(new RateLimiter())->allow('request_reset',3,3600,RateLimiter::identity())) {
            json_response(['success'=>false,'error'=>'Aguarda antes de pedir outra recuperação.'],429);
        }
        $email=strtolower(trim((string)(auth_payload()['email']??'')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            $stmt=db()->prepare('SELECT id,name,email FROM users WHERE email=? AND email_verified_at IS NOT NULL AND active=1 LIMIT 1');
            $stmt->execute([$email]);
            $user=$stmt->fetch();
            if($user){
                $token=AccountTokenService::issue(db(),(int)$user['id'],'password_reset',1);
                try {
                    if(!AccountMailer::sendPasswordReset($user['email'],$user['name'],$token)) {
                        error_log('SA could not send password reset email.');
                    }
                } catch(Throwable $mailError) { error_log('SA password reset email: '.$mailError->getMessage()); }
            }
        }
        json_response(['success'=>true,'message'=>'Se existir uma conta confirmada com esse e-mail, enviaremos instruções para recuperar o acesso.']);
    }

    if($action==='reset_password'){
        require_post();
        if(!(new RateLimiter())->allow('reset_password',5,900,RateLimiter::identity())) {
            json_response(['success'=>false,'error'=>'Demasiadas tentativas. Tenta novamente mais tarde.'],429);
        }
        $d=auth_payload();
        $token=(string)($d['token']??'');
        $password=(string)($d['password']??'');
        $confirmation=(string)($d['password_confirmation']??'');
        if(strlen($password)<10 || strlen($password)>72) json_response(['success'=>false,'error'=>'A palavra-passe deve ter entre 10 e 72 bytes.'],422);
        if(!hash_equals($password,$confirmation)) json_response(['success'=>false,'error'=>'As palavras-passe não coincidem.'],422);

        $pdo=db();
        $pdo->beginTransaction();
        try {
            $userId=AccountTokenService::consume($pdo,$token,'password_reset');
            if(!$userId) {
                $pdo->rollBack();
                json_response(['success'=>false,'error'=>'Este link é inválido, expirou ou já foi utilizado.'],400);
            }
            $stmt=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND active=1 AND email_verified_at IS NOT NULL');
            $stmt->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
            if($stmt->rowCount()!==1) {
                $pdo->rollBack();
                json_response(['success'=>false,'error'=>'Não foi possível actualizar esta conta.'],400);
            }
            $pdo->prepare("UPDATE account_tokens SET used_at=NOW() WHERE user_id=? AND purpose='password_reset' AND used_at IS NULL")->execute([$userId]);
            $pdo->commit();
        } catch(Throwable $error) {
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        Auth::audit('account.password_reset',[], $userId);
        json_response(['success'=>true,'message'=>'Palavra-passe actualizada. Já podes iniciar sessão.']);
    }

    if($action==='login'){
        require_post();
        if(!(new RateLimiter())->allow('login',10,900,RateLimiter::identity())) json_response(['success'=>false,'error'=>'Demasiadas tentativas. Tenta novamente mais tarde.'],429);
        $d=auth_payload();
        if(Auth::login(trim((string)($d['email']??'')),(string)($d['password']??''))) json_response(['success'=>true,'user'=>Auth::user()]);
        json_response(['success'=>false,'error'=>'Credenciais inválidas ou e-mail ainda não confirmado.'],401);
    }
    if($action==='logout'){ Auth::requireCsrf(); Auth::logout(); json_response(['success'=>true]); }
    if($action==='me'){ json_response(['success'=>true,'user'=>Auth::user()]); }
    json_response(['success'=>false,'error'=>'Ação inválida'],400);
} catch(Throwable $e){
    error_log('SA auth error: '.$e->getMessage());
    json_response(['success'=>false,'error'=>'Ocorreu um erro ao processar o pedido.'],500);
}
