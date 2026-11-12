<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';

final class EmbeddingService {
    public function embed(string $text): array {
        $url=rtrim((string)env('OLLAMA_API_URL','http://127.0.0.1:11434/api/chat'),'/api/chat').'/api/embed';
        $model=(string)env('OLLAMA_EMBEDDING_MODEL','nomic-embed-text');
        $payload=json_encode(['model'=>$model,'input'=>$text],JSON_UNESCAPED_UNICODE);
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>60]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $d=json_decode((string)$raw,true);
        if($code>=400||!isset($d['embeddings'][0])) throw new RuntimeException('Modelo de embeddings indisponível. Instala com: ollama pull '.$model);
        return $d['embeddings'][0];
    }
}
