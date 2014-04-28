<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPConnection;
use DoSomething\MBStatTracker\StatHat;

require('mb-secure-config.inc');
require('mb-config.inc');

$credentials = array (
  'host' => getenv('RABBITMQ_HOST') ? getenv('RABBITMQ_HOST') : 'localhost',
  'port' => getenv('RABBITMQ_PORT') ? getenv('RABBITMQ_PORT') : '5672',
  'username' => getenv('RABBITMQ_USERNAME') ? getenv('RABBITMQ_USERNAME') : 'guest',
  'password' => getenv('RABBITMQ_PASSWORD') ? getenv('RABBITMQ_PASSWORD') : 'guest',
  'vhost' => getenv('RABBITMQ_VHOST') ? getenv('RABBITMQ_VHOST') : '',
);

$config = array(
  // Routing key
  'routingKey' => getenv('MB_USER_MAILCHIMP_STATUS_ROUTING_KEY'),

  // Consume config
  'consume' => array(
    'consumer_tag' => '',
    'no_local' => FALSE,
    'no_ack' => FALSE,
    'exclusive' => FALSE,
    'nowait' => FALSE,
  ),

  // Exchange config
  'exchange' => array(
    'name' => getenv('MB_USER_MAILCHIMP_STATUS_EXCHANGE'),
    'type' => getenv('MB_USER_MAILCHIMP_STATUS_EXCHANGE_TYPE'),
    'passive' => getenv('MB_USER_MAILCHIMP_STATUS_EXCHANGE_PASSIVE'),
    'durable' => getenv('MB_USER_MAILCHIMP_STATUS_EXCHANGE_DURABLE'),
    'auto_delete' => getenv('MB_USER_MAILCHIMP_STATUS_EXCHANGE_AUTO_DELETE'),
  ),

  // Queue config
  'queue' => array(
    array(
      'name' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE'),
      'passive' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE_PASSIVE'),
      'durable' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE_DURABLE'),
      'exclusive' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE_EXCLUSIVE'),
      'auto_delete' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE_AUTO_DELETE'),
      'bindingKey' => getenv('MB_USER_MAILCHIMP_STATUS_QUEUE_BINDING_KEY'),
    ),
  ),
);


// Establish connection with the message broker.
try {
  $mb = new MessageBroker($credentials, $config);
}
catch (Exception $e) {
  echo $e->getMessage();
  echo "\nUnable to establish a connection with the Message Broker. Exiting...\n";
  exit;
}

// Callback to handle messages received by this consumer.
$callback = function($payload) {
  // StatHat tracking setup.
  $statHat = new StatHat(getenv('STATHAT_EZKEY'), 'mbc-mailchimp-status:');
  $statHat->setIsProduction(getenv('USE_STAT_TRACKING') ? getenv('USE_STAT_TRACKING') : FALSE);

  // Producer serialized the data before publishing the message to the broker.
  $payloadBody = unserialize($payload->body);

  // Mailchimp error details place the email in a nested email array.
  if (!isset($payloadBody['email']['email'])) {
    echo "Email not received in payload\n";
    $statHat->addStatName('no email in payload');

    // Send acknowledgement in these cases where data is missing because we're
    // not going to actually ever be able to do anything with them.
    MessageBroker::sendAck($payload);
    return;
  }

  if (!isset($payloadBody['code'])) {
    echo "Status code not received in payload\n";
    $statHat->addStatName('no status code in payload');

    MessageBroker::sendAck($payload);
    return;
  }

  // Package fields to POST to the user API
  $email = $payloadBody['email']['email'];
  $mailchimpStatus = $payloadBody['code'];
  $postFields = array(
    'email' => $email,
    'mailchimp_status' => $mailchimpStatus,
  );

  // POST update to the user API
  $userApiHost = getenv('DS_USER_API_HOST') ? getenv('DS_USER_API_HOST') : 'localhost';
  $userApiPort = getenv('DS_USER_API_PORT') ? getenv('DS_USER_API_PORT') : 4722;
  $userApiUrl = $userApiHost . ':' . $userApiPort . '/user';

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $userApiUrl);
  curl_setopt($ch, CURLOPT_POST, count($postFields));
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  $result = curl_exec($ch);
  curl_close($ch);

  if ($result == TRUE) {
    echo "Updated Mailchimp status ($mailchimpStatus) for email: $email\n";
    $statHat->addStatName('success');

    // Only send acknowledgement on success
    MessageBroker::sendAck($payload);
  }
  else {
    echo "FAILED to update Mailchimp status ($mailchimpStatus) for email: $email\n";
    $statHat->addStatName('update failed');
  }

  // Report to StatHat. Only report a single instance of the event.
  $statHat->reportCount(1);
};

// Start consuming messages
$mb->consumeMessage($callback);

?>
