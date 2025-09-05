<?php
require __DIR__ . '/vьendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Predis\Client as RedisClient;
use React\Socket\SocketServer;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;

class MultiplayerGame implements MessageComponentInterface {
    protected $clients;
    protected $redis;

    public function __construct() {
        $this->clients = new \SplObjectStorage;

        // Подключение к Redis через переменные окружения
        $this->redis = new RedisClient([
            'scheme' => 'tcp',
            'host'   => getenv('REDIS_HOST') ?: 'redis',
            'port'   => getenv('REDIS_PORT') ?: 6379,
        ]);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->send(json_encode(['type' => 'connected', 'message' => 'Welcome!']));

        echo "Новое подключение: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data) return;

        switch ($data['type']) {
            case 'join':
                $roomId = $data['room'];
                $nickname = $data['nickname'];

                // Создаем игрока, если его нет
                if (!$this->redis->hexists("room:{$roomId}:players", $nickname)) {
                    $this->redis->hset("room:{$roomId}:players", $nickname, json_encode(['tries'=>0, 'score'=>0]));
                }

                // Создаем комнату с ответом если нет
                if (!$this->redis->exists("room:{$roomId}:answer:nickname")) {
                    $playersList = json_decode(file_get_contents(__DIR__.'/players.json'), true);
                    $random = $playersList[rand(0, count($playersList)-1)];
                    error_log('random player' . json_encode($random));
                    $this->redis->hset("room:{$roomId}:answer", 'nickname', $random['nickname']);
                    $this->redis->hset("room:{$roomId}:answer", 'uuid', $random['id'] ?? '');
                }

                // Отправляем обновленный лидерборд
                $players = $this->getPlayers($roomId);
                $from->send(json_encode(['type'=>'joined','room'=>$roomId,'leaderboard'=>$players]));
                break;

            case 'guess':
                $roomId = $data['room'];
                $nickname = $data['nickname'];
                $guess = $data['guess'];

                $answer = $this->redis->hget("room:{$roomId}:answer", 'nickname');
                $uuid = $this->redis->hget("room:{$roomId}:answer", 'uuid');

                $playerData = json_decode($this->redis->hget("room:{$roomId}:players", $nickname), true);
                $playerData['tries'] = ($playerData['tries'] ?? 0) + 1;

                $message = [
                    'type'=>'update',
                    'player'=>$nickname,
                    'guess'=>$guess,
                    'tries'=>$playerData['tries']
                ];

                if ($guess === $answer) {
                    $playerData['score'] = ($playerData['score'] ?? 0) + 1;
                    $message['correct'] = true;
                    $message['answer'] = $answer;
                    $message['photo'] = "https://assets.blast.tv/images/players/{$uuid}?format=auto";
                }

                $this->redis->hset("room:{$roomId}:players", $nickname, json_encode($playerData));

                // Рассылаем всем клиентам
                $players = $this->getPlayers($roomId);
                foreach ($this->clients as $client) {
                    $client->send(json_encode(array_merge($message,['room'=>$roomId,'leaderboard'=>$players])));
                }
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Отключение: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Ошибка: {$e->getMessage()}\n";
        $conn->close();
    }

    private function getPlayers($roomId) {
        $playersRaw = $this->redis->hgetall("room:{$roomId}:players");
        $players = [];
        foreach ($playersRaw as $nick => $json) {
            $players[$nick] = json_decode($json, true);
        }
        return $players;
    }
}

$loop = Factory::create();

// SocketServer принимает адрес и loop
$socket = new SocketServer('0.0.0.0:8080', [], $loop);

$server = new IoServer(
    new HttpServer(
        new WsServer(
            new MultiplayerGame()
        )
    ),
    $socket,
    $loop
);

echo "Сервер запущен на ws://0.0.0.0:8080\n";

$server->run();
