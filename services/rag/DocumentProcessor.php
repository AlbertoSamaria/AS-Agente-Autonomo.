<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';
require_once __DIR__.'/EmbeddingService.php';

final class DocumentProcessor {
    public function process(int $documentId): array {
        $s=db()->prepare("SELECT * FROM documents WHERE id=?");$s->execute([$documentId]);$d=$s->fetch();
        if(!$d) throw new RuntimeException('Documento inexistente.');
        $path=dirname(__DIR__,2).'/uploads/'.$d['stored_name'];
        $text=$this->extract($path,$d['mime_type']);
        $text=preg_replace('/\s+/u',' ',trim($text));
        if($text==='') throw new RuntimeException('Não foi possível extrair texto.');
        $chunks=$this->chunk($text,1200,180);
        db()->prepare("UPDATE documents SET status='processing',error_message=NULL WHERE id=?")->execute([$documentId]);
        db()->prepare("DELETE FROM document_chunks WHERE document_id=?")->execute([$documentId]);
        $emb=new EmbeddingService();
        foreach($chunks as $i=>$chunk){
            $vector=$emb->embed($chunk);
            db()->prepare("INSERT INTO document_chunks(document_id,chunk_index,content,embedding_json) VALUES(?,?,?,?)")->execute([$documentId,$i,$chunk,json_encode($vector)]);
        }
        db()->prepare("UPDATE documents SET status='ready' WHERE id=?")->execute([$documentId]);
        return ['success'=>true,'chunks'=>count($chunks)];
    }
    private function extract(string $path,string $mime): string {
        if(str_contains($mime,'text/plain')||str_contains($mime,'text/csv')) return (string)file_get_contents($path);
        if(str_contains($mime,'wordprocessingml.document')) {
            $zip=new ZipArchive(); if($zip->open($path)!==true) throw new RuntimeException('DOCX inválido.');
            $xml=$zip->getFromName('word/document.xml')?:'';$zip->close();
            return html_entity_decode(strip_tags(str_replace(['</w:p>','</w:tr>'],"\n",$xml)),ENT_QUOTES|ENT_XML1,'UTF-8');
        }
        if(str_contains($mime,'spreadsheetml.sheet')) {
            $zip=new ZipArchive(); if($zip->open($path)!==true) throw new RuntimeException('XLSX inválido.');
            $shared=[];$sx=$zip->getFromName('xl/sharedStrings.xml');
            if($sx){$xml=simplexml_load_string($sx); foreach($xml->si as $si)$shared[]=(string)($si->t??'');}
            $out=[];$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
            if($sheet){$xml=simplexml_load_string($sheet); foreach($xml->sheetData->row as $row){$vals=[];foreach($row->c as $c){$v=(string)$c->v;$vals[]=((string)$c['t']==='s'&&isset($shared[(int)$v])?$shared[(int)$v]:$v);} $out[]=implode(' | ',$vals);}}
            $zip->close(); return implode("\n",$out);
        }
        if($mime==='application/pdf'){
            $raw=(string)file_get_contents($path);
            preg_match_all('/\(([^\)]{2,})\)/s',$raw,$m);
            return implode("\n",$m[1]??[]);
        }
        throw new RuntimeException('Formato não suportado: '.$mime);
    }
    private function chunk(string $text,int $size,int $overlap): array {
        $out=[];$len=mb_strlen($text);$start=0;
        while($start<$len){$out[]=mb_substr($text,$start,$size);$start += max(1,$size-$overlap);}
        return $out;
    }
}
