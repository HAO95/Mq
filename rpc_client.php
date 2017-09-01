<?php

require_once __DIR__ . '/rabbit/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class FibonacciRpcClient {
	private $connection;
	private $channel;
	private $callback_queue;
	private $response;
	private $corr_id;

	public function __construct() {
		$this->connection = new AMQPStreamConnection(
			'127.0.0.1', 5672, 'guest', 'guest');
		$this->channel = $this->connection->channel();
		list($this->callback_queue, ,) = $this->channel->queue_declare(
			"", false, false, true, false);
		$this->channel->basic_consume(
			$this->callback_queue, '', false, false, false, false,
			array($this, 'on_response'));
	}
	public function on_response($rep) {
		if($rep->get('correlation_id') == $this->corr_id) {
			$this->response = $rep->body;
		}
	}

	public function call($n) {
		$this->response = null;
		$this->corr_id = uniqid();

		$msg = new AMQPMessage(
			(string) $n,
			array('correlation_id' => $this->corr_id,
			      'reply_to' => $this->callback_queue)
			);
		$this->channel->basic_publish($msg, '', 'rpc_queue');
		while(!$this->response) {
			$this->channel->wait();
		}
		return intval($this->response);
	}
};
$data['member_id'] = 1;
$data['goods_pack_id'] = 5;
$data['realname'] = 'AnYe!95';
$data['sex'] = 1;
$data['phoneNumber'] = 13558000955;
$data['arrives_time'] = '1503902100';
$data['amount'] = 5;
$data['order_type'] = 2;

$str = json_encode($data);
//$value = serialize($str);
$fibonacci_rpc = new FibonacciRpcClient();
$response = $fibonacci_rpc->call($str);
echo " [.] Got ", $response, "\n";


