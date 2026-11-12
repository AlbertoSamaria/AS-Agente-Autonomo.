<?php
declare(strict_types=1);
final class WebTool {
    public function search(string $query): array {
        $query=trim($query);
        if($query===''||mb_strlen($query)>300) return ['success'=>false,'error'=>'Pesquisa inválida.'];

        $url='https://html.duckduckgo.com/html/?q='.rawurlencode($query);
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_USERAGENT=>'SA-Agent/1.0',
        ]);
        $body=curl_exec($ch);
        $error=curl_error($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($body===false||$code<200||$code>=400) {
            return ['success'=>false,'error'=>$error?:'A pesquisa web não está disponível.'];
        }

        $previous=libxml_use_internal_errors(true);
        $document=new DOMDocument();
        $document->loadHTML((string)$body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath=new DOMXPath($document);
        $links=$xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]');
        $results=[];
        foreach($links as $link) {
            $href=html_entity_decode((string)$link->getAttribute('href'),ENT_QUOTES|ENT_HTML5,'UTF-8');
            $parts=parse_url($href);
            if(($parts['host']??'')==='duckduckgo.com') {
                parse_str($parts['query']??'',$params);
                $href=(string)($params['uddg']??'');
            }
            if(!filter_var($href,FILTER_VALIDATE_URL)||parse_url($href,PHP_URL_SCHEME)!=='https') continue;
            $snippetNode=$xpath->query('following::a[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")][1]', $link)->item(0);
            $results[]=[
                'title'=>trim($link->textContent),
                'url'=>$href,
                'snippet'=>$snippetNode?trim($snippetNode->textContent):'',
            ];
            if(count($results)>=5) break;
        }
        return ['success'=>true,'query'=>$query,'results'=>$results];
    }

    public function get(string $url): array {
        if(!filter_var($url,FILTER_VALIDATE_URL)) return ['success'=>false,'error'=>'URL inválida'];
        $parts=parse_url($url);
        if(($parts['scheme']??'')!=='https') return ['success'=>false,'error'=>'Apenas HTTPS permitido'];
        $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'SA-Agent/1.0']);
        $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        return ['success'=>$code>=200&&$code<400,'status'=>$code,'content'=>mb_substr(strip_tags((string)$body),0,12000)];
    }
}
