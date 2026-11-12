<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/ai/Ollama.php';
require_once dirname(__DIR__).'/services/ai/LlamaCpp.php';

try{
    RBAC::require('chat');
    $d=json_decode(file_get_contents('php://input'),true)?:[];
    $messages=$d['messages']??[];
    if(!$messages) throw new RuntimeException('Mensagens ausentes.');
    $provider=strtolower((string)env('LLM_PROVIDER','ollama'));
    if(!in_array($provider,['ollama','llama.cpp'],true)) throw new RuntimeException('Fornecedor de modelos não suportado: '.$provider);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    if($provider==='llama.cpp'){
        $url=rtrim((string)env('LLAMACPP_BASE_URL','http://127.0.0.1:8080'),'/').'/v1/chat/completions';
        $payload=json_encode(['model'=>env('LLAMACPP_MODEL','local-model'),'messages'=>$messages,'stream'=>true,'temperature'=>0.35],JSON_UNESCAPED_UNICODE);
    }else{
        $url=(string)env('OLLAMA_API_URL');
        $payload=json_encode(['model'=>env('OLLAMA_MODEL','qwen2.5:1.5b'),'messages'=>$messages,'stream'=>true],JSON_UNESCAPED_UNICODE);
    }
    if($payload===false) throw new RuntimeException('Não foi possível preparar o pedido de streaming.');
    $ch=curl_init($url);
    if($ch===false) throw new RuntimeException('Não foi possível inicializar o cliente HTTP.');
    $buffer='';
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>(int)env($provider==='llama.cpp'?'LLAMACPP_TIMEOUT':'OLLAMA_TIMEOUT',120),
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$buffer,$provider){
            $buffer.=$chunk;
            while(($newline=strpos($buffer,"\n"))!==false){
                $line=trim(substr($buffer,0,$newline));
                $buffer=substr($buffer,$newline+1);
                if($line==='') continue;
                if($provider==='llama.cpp'){
                    if(!str_starts_with($line,'data:')) continue;
                    $line=trim(substr($line,5));
                    if($line==='[DONE]'){echo "data: [DONE]\n\n";@ob_flush();@flush();continue;}
                }
                $data=json_decode($line,true);
                $content=$provider==='llama.cpp'
                    ?($data['choices'][0]['delta']['content']??null)
                    :($data['message']['content']??null);
                if(is_string($content)) echo "data: ".json_encode(['content'=>$content],JSON_UNESCAPED_UNICODE)."\n\n";
                if($provider==='ollama'&&!empty($data['done'])) echo "data: [DONE]\n\n";
                @ob_flush(); @flush();
            }
            return strlen($chunk);
        }
    ]);
    $result=curl_exec($ch);
    $curlError=curl_error($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($result===false||$status>=400) throw new RuntimeException($result===false?'Erro no streaming: '.$curlError:'Servidor de modelos retornou HTTP '.$status);
}catch(Throwable $e){
    if(!headers_sent()) header('Content-Type: text/event-stream; charset=utf-8');
    echo "data: ".json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE)."\n\n";
}
