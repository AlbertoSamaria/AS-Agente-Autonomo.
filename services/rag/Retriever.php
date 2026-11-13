<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';

final class Retriever {
    private function cosine(array $a,array $b): ?float {
        if(count($a)===0 || count($a)!==count($b)) return null;
        $dot=0;$na=0;$nb=0;$n=count($a);
        for($i=0;$i<$n;$i++){ $dot+=$a[$i]*$b[$i];$na+=$a[$i]*$a[$i];$nb+=$b[$i]*$b[$i]; }
        return ($na&&$nb)?$dot/(sqrt($na)*sqrt($nb)):0;
    }
    public function search(array $queryEmbedding,int $limit,int $userId,array $documentIds): array {
        if($userId<=0 || $documentIds===[] || $limit<=0) return [];
        $documentIds=array_values(array_unique(array_filter(
            array_map('intval',$documentIds),
            static fn(int $id): bool=>$id>0
        )));
        if($documentIds===[]) return [];
        $placeholders=implode(',',array_fill(0,count($documentIds),'?'));
        $stmt=db()->prepare("SELECT dc.id,dc.chunk_index,dc.content,dc.embedding_json,d.original_name FROM document_chunks dc JOIN documents d ON d.id=dc.document_id WHERE d.status='ready' AND d.user_id=? AND d.id IN ($placeholders) AND dc.embedding_json IS NOT NULL");
        $stmt->execute(array_merge([$userId],$documentIds));
        $rows=$stmt->fetchAll();
        $out=[];
        foreach($rows as $r){
            $embedding=json_decode($r['embedding_json'],true);
            if(!is_array($embedding)) continue;
            $score=$this->cosine($queryEmbedding,$embedding);
            if($score===null) continue;
            $out[]=[
                'score'=>$score,
                'content'=>(string)$r['content'],
                'document'=>(string)$r['original_name'],
                'chunk_index'=>(int)$r['chunk_index'],
            ];
        }
        usort($out,fn($a,$b)=>$b['score']<=>$a['score']); return array_slice($out,0,$limit);
    }
}
