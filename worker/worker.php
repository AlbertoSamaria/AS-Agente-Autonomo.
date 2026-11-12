<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/services/scheduler/Scheduler.php';
require_once dirname(__DIR__).'/services/notifications/NotificationService.php';

echo "SA Worker iniciado. Ctrl+C para parar.\n";
while(true){
    try{
        $items=(new Scheduler())->due();
        foreach($items as $item){
            $payload=json_decode($item['payload_json']??'{}',true)?:[];
            if(($item['action_type']??'')==='notification'){
                (new NotificationService())->send((int)$item['user_id'],$payload['title']??$item['name'],$payload['message']??'Automação executada.');
            }
            (new Scheduler())->markRun((int)$item['id']);
            echo date('c')." schedule #".$item['id']." executado\n";
        }
    }catch(Throwable $e){fwrite(STDERR,date('c')." erro: ".$e->getMessage()."\n");}
    sleep(30);
}
