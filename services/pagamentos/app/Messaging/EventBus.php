<?php

namespace App\Messaging;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Barramento de eventos sobre RabbitMQ (exchange do tipo "topic").
 * Copiado igual em todo serviço que publica ou consome eventos.
 *
 * composer require php-amqplib/php-amqplib
 */
class EventBus
{
    private const EXCHANGE = 'lanchonete.eventos';

    private ?AMQPStreamConnection $conn = null;
    private $channel = null;

    public function __construct()
    {
        // O RabbitMQ pode levar alguns segundos para aceitar conexões: tenta de novo antes de desistir
        for ($tentativa = 1; ; $tentativa++) {
            try {
                $this->conn = new AMQPStreamConnection(
                    env('RABBITMQ_HOST', 'rabbitmq'), 5672,
                    env('RABBITMQ_USER', 'guest'), env('RABBITMQ_PASS', 'guest')
                );
                break;
            } catch (\Throwable $e) {
                if ($tentativa >= 15) {
                    throw $e;
                }
                error_log("[EventBus] RabbitMQ indisponível ({$e->getMessage()}), tentando de novo em 2s...");
                sleep(2);
            }
        }
        $this->channel = $this->conn->channel();
        $this->channel->exchange_declare(self::EXCHANGE, AMQPExchangeType::TOPIC, false, true, false);
    }

    /** Publica um evento, ex.: publicar('pedido.criado', [...]) */
    public function publicar(string $evento, array $dados): void
    {
        $msg = new AMQPMessage(json_encode([
            'evento'    => $evento,
            'id'        => (string) \Illuminate\Support\Str::uuid(), // para idempotência
            'ocorrido'  => now()->toIso8601String(),
            'dados'     => $dados,
        ]), [
            'content_type'  => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->channel->basic_publish($msg, self::EXCHANGE, $evento);
    }

    /**
     * Fica escutando os eventos indicados numa fila própria do serviço.
     * @param array<string, callable> $handlers  ['pagamento.aprovado' => fn(array $dados) => ...]
     */
    public function consumir(string $fila, array $handlers): void
    {
        $this->channel->queue_declare($fila, false, true, false, false);
        foreach (array_keys($handlers) as $evento) {
            $this->channel->queue_bind($fila, self::EXCHANGE, $evento);
        }
        $this->channel->basic_qos(0, 10, false);

        $this->channel->basic_consume($fila, '', false, false, false, false,
            function (AMQPMessage $msg) use ($handlers) {
                $payload = json_decode($msg->getBody(), true);
                try {
                    ($handlers[$payload['evento']])($payload['dados'], $payload);
                    $msg->ack();
                } catch (\Throwable $e) {
                    report($e);
                    $msg->nack(requeue: false); // vai para DLQ se configurada
                }
            });

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function __destruct()
    {
        $this->channel?->close();
        $this->conn?->close();
    }
}
