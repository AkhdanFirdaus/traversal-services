<?php

use ElephantIO\Client;

$url = 'http://server:3000';
// if socket server is served in non root path, adjust the url to include those path, e.g:
// $url = 'http://localhost:8080/my'

// if client option is omitted then it will use latest client available,
// aka. version 4.x
$options = ['client' => Client::CLIENT_4X];

$client = Client::create($url, $options);
$client->connect();
$client->of('/'); // can be omitted if connecting to default namespace

// emit an event to the server
$data = ['username' => 'my-user'];
$client->emit('get-user-info', $data);

// wait an event to arrive
// beware when waiting for response from server, the script may be killed if
// PHP max_execution_time is reached
if ($packet = $client->wait('user-info')) {
    // an event has been received, the result will be a \ElephantIO\Engine\Packet class
    // data property contains the first argument
    // args property contains array of arguments, [$data, ...]
    $data = $packet->data;
    $args = $packet->args;
    // access data
    $email = $data['email'];
}

// end session
$client->disconnect();