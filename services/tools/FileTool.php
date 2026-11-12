<?php
declare(strict_types=1);
final class FileTool {
    public function listUploads(): array {
        $dir=dirname(__DIR__,2).'/uploads'; $files=[];
        foreach(scandir($dir) as $f) if($f!=='.'&&$f!=='..') $files[]=['name'=>$f,'size'=>filesize($dir.'/'.$f)];
        return ['success'=>true,'files'=>$files];
    }
}
