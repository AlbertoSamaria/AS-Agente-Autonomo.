<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';

final class Retriever {
    private function cosine(array $a,array $b): float {
        $dot=0;$na=0;$nb=0;$n=min(count($a),count($b));
        for($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i];$na+=$a[$i]*$a[$i];$nb+=$b[$i]*$b[$i]; }
        return ($na&&$nb)?$dot/(sqrt($na)*sqrt($nb)):0;
    }
    public function search(array $queryEmbedding,int $limit,int $userId,array $documentIds): array {
        if($documentIds===[]) return [];
        $placeholders=implode(',',array_fill(0,count($documentIds),'?'));
        $stmt=db()->prepare("SELECT dc.id,dc.content,dc.embedding_json,d.original_name FROM document_chunks dc JOIN documents d ON d.id=dc.document_id WHERE d.status='ready' AND d.user_id=? AND d.id IN ($placeholders) AND dc.embedding_json IS NOT NULL");
        $stmt->execute(array_merge([$userId],$documentIds));
        $rows=$stmt->fetchAll();
        $out=[];
        foreach($rows as $r){ $e=json_decode($r['embedding_json'],true); if(is_array($e)) $out[]=['score'=>$this->cosine($queryEmbedding,$e),'content'=>$r['content'],'document'=>$r['original_name']]; }
        usort($out,fn($a,$b)=>$b['score']<=>$a['score']); return array_slice($out,0,$limit);
    }
}
