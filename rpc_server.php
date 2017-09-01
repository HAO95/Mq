<?php

require_once __DIR__ . '/rabbit/vendor/autoload.php';
require_once 'phppdo.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection();
$channel = $connection->channel();

$channel->queue_declare('rpc_queue', false, false, false, false);


echo " [x] Awaiting RPC requests\n";
$callback = function($req) {

  $data = json_decode($req->body,TRUE);
  
  if($data['order_type'] == 1)
  {
    $msg = new AMQPMessage(
            (string) mqpdo::submitSeatOrder($data), array('correlation_id' => $req->get('correlation_id'))
    );
  }else 
  {
       $msg = new AMQPMessage(
            (string) mqpdo::submitPackOrder($data), array('correlation_id' => $req->get('correlation_id'))
    );   
  }
  
    $req->delivery_info['channel']->basic_publish(
            $msg, '', $req->get('reply_to'));
    $req->delivery_info['channel']->basic_ack(
            $req->delivery_info['delivery_tag']);
};

$channel->basic_qos(null, 1, null);
$channel->basic_consume('rpc_queue', '', false, false, false, false, $callback);

while (count($channel->callbacks)) {
    $channel->wait();
}

$channel->close();
$connection->close();


