<?php

class NetworkSimulator {
    private $queues;
    private $eventQueue;
    private $randIndex;
    private $randomNumbers;
    private $currentTime;
    private $maxRand;

    public function __construct($queueParams, $randomNumbers) {
        $this->queues = [];
        foreach ($queueParams as $params) {
            $this->queues[] = [
                'servers' => $params['servers'],
                'capacity' => $params['capacity'],
                'arrivalRange' => $params['arrivalRange'] ?? null,
                'serviceRange' => $params['serviceRange'],
                'currentCustomers' => 0,
                'serviceTimes' => [],
                'stateDurations' => [],
                'losses' => 0
            ];
        }
        $this->eventQueue = new SplPriorityQueue();
        $this->eventQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->randIndex = 0;
        $this->randomNumbers = $randomNumbers;
        $this->currentTime = 0;
        $this->maxRand = count($randomNumbers);
    }

    private function addEvent($time, $type, $queueId = null) {
        $this->eventQueue->insert(['time' => $time, 'type' => $type, 'queueId' => $queueId], -$time);
    }

    public function simulate() {
        // Primeira chegada na primeira fila no tempo 1.5
        if (isset($this->queues[0]['arrivalRange'])) {
            $this->addEvent(1.5, 'arrival', 0);
        }

        while (!$this->eventQueue->isEmpty() && $this->randIndex < $this->maxRand) {
            $event = $this->eventQueue->extract();
            $eventData = $event['data'];
            $eventTime = $eventData['time'];
            $eventType = $eventData['type'];
            $queueId = $eventData['queueId'];

            // Atualiza durações dos estados
            $delta = $eventTime - $this->currentTime;
            foreach ($this->queues as $qId => &$queue) {
                $state = $queue['currentCustomers'];
                if (!isset($queue['stateDurations'][$state])) {
                    $queue['stateDurations'][$state] = 0;
                }
                $queue['stateDurations'][$state] += $delta;
            }
            unset($queue);

            $this->currentTime = $eventTime;

            switch ($eventType) {
                case 'arrival':
                    $this->processArrival($queueId);
                    break;
                case 'departure':
                    $this->processDeparture($queueId);
                    break;
            }
        }
    }

    private function processArrival($queueId) {
        $queue = &$this->queues[$queueId];
        
        if ($queue['currentCustomers'] < $queue['capacity']) {
            $queue['currentCustomers']++;
            
            if (count($queue['serviceTimes']) < $queue['servers']) {
                $serviceTime = $this->generateServiceTime($queueId);
                $departureTime = $this->currentTime + $serviceTime;
                $queue['serviceTimes'][] = $departureTime;
                sort($queue['serviceTimes']);
                $this->addEvent($departureTime, 'departure', $queueId);
            }
            
            // Agenda próxima chegada apenas para a primeira fila
            if ($queueId == 0 && isset($queue['arrivalRange'])) {
                $nextArrivalTime = $this->currentTime + $this->generateArrivalInterval($queueId);
                $this->addEvent($nextArrivalTime, 'arrival', 0);
            }
        } else {
            $queue['losses']++;
        }
    }

    private function processDeparture($queueId) {
        $queue = &$this->queues[$queueId];
        array_shift($queue['serviceTimes']);
        $queue['currentCustomers']--;

        // Atende próximo cliente na fila, se houver
        if ($queue['currentCustomers'] >= $queue['servers']) {
            $serviceTime = $this->generateServiceTime($queueId);
            $departureTime = $this->currentTime + $serviceTime;
            $queue['serviceTimes'][] = $departureTime;
            sort($queue['serviceTimes']);
            $this->addEvent($departureTime, 'departure', $queueId);
        }

        // Se não for a última fila, transfere para a próxima
        if ($queueId < count($this->queues) - 1) {
            $this->transferToQueue($queueId + 1);
        }
    }

    private function transferToQueue($targetQueueId) {
        $targetQueue = &$this->queues[$targetQueueId];
        
        if ($targetQueue['currentCustomers'] < $targetQueue['capacity']) {
            $targetQueue['currentCustomers']++;
            
            if (count($targetQueue['serviceTimes']) < $targetQueue['servers']) {
                $serviceTime = $this->generateServiceTime($targetQueueId);
                $departureTime = $this->currentTime + $serviceTime;
                $targetQueue['serviceTimes'][] = $departureTime;
                sort($targetQueue['serviceTimes']);
                $this->addEvent($departureTime, 'departure', $targetQueueId);
            }
        } else {
            $targetQueue['losses']++;
        }
    }

    private function generateArrivalInterval($queueId) {
        $queue = $this->queues[$queueId];
        $rnd = $this->randomNumbers[$this->randIndex++];
        return $queue['arrivalRange'][0] + ($queue['arrivalRange'][1] - $queue['arrivalRange'][0]) * $rnd;
    }

    private function generateServiceTime($queueId) {
        $queue = $this->queues[$queueId];
        $rnd = $this->randomNumbers[$this->randIndex++];
        return $queue['serviceRange'][0] + ($queue['serviceRange'][1] - $queue['serviceRange'][0]) * $rnd;
    }

    public function getReport() {
        $report = [];
        foreach ($this->queues as $qId => $queue) {
            $totalTime = array_sum($queue['stateDurations']);
            $states = [];
            foreach ($queue['stateDurations'] as $state => $time) {
                $states[$state] = [
                    'time' => round($time, 4),
                    'percentage' => round(($time / $totalTime) * 100, 2)
                ];
            }
            ksort($states);
            $report[$qId] = [
                'states' => $states,
                'losses' => $queue['losses'],
                'total_time' => round($totalTime, 4)
            ];
        }
        return $report;
    }

    public function getGlobalTime() {
        return round($this->currentTime, 4);
    }
}

// Processa argumentos da linha de comando
$options = getopt("", ["queues:", "random::"]);

if (!isset($options['queues'])) {
    die("Uso: php simuladorFilaV1.php --queues='servers,capacity,arrival_min,arrival_max,service_min,service_max|...' [--random=0.1,0.2,...]\n");
}

// Parse das filas
$queueParams = [];
$queuesConfig = explode('|', $options['queues']);
foreach ($queuesConfig as $queueConfig) {
    $params = explode(',', $queueConfig);
    if (count($params) != 6) {
        die("Formato inválido para fila. Use: servers,capacity,arrival_min,arrival_max,service_min,service_max\n");
    }
    
    $queueParams[] = [
        'servers' => (int)$params[0],
        'capacity' => (int)$params[1],
        'arrivalRange' => [(float)$params[2], (float)$params[3]],
        'serviceRange' => [(float)$params[4], (float)$params[5]]
    ];
}

// Remove arrivalRange para filas que não são a primeira
for ($i = 1; $i < count($queueParams); $i++) {
    unset($queueParams[$i]['arrivalRange']);
}

// Parse números aleatórios
$randomNumbers = isset($options['random']) ? 
    array_map('floatval', explode(',', $options['random'])) : 
    [];

$simulator = new NetworkSimulator($queueParams, $randomNumbers);
$simulator->simulate();

$report = $simulator->getReport();
$globalTime = $simulator->getGlobalTime();

echo "===== RELATÓRIO DA SIMULAÇÃO =====\n\n";

foreach ($report as $qId => $queueReport) {
    $queueNumber = $qId + 1;
    echo "Fila $queueNumber\n";
    echo "Estado | Tempo acumulado | Probabilidade\n";
    foreach ($queueReport['states'] as $state => $data) {
        echo str_pad($state, 6, ' ', STR_PAD_BOTH) . " | " . 
             str_pad(number_format($data['time'], 4), 15, ' ', STR_PAD_BOTH) . " | " .
             str_pad(number_format($data['percentage'], 2)."%", 14, ' ', STR_PAD_BOTH) . "\n";
    }
    echo "\nNúmero de clientes perdidos: {$queueReport['losses']}\n";
    echo "Tempo acumulado da fila: " . $queueReport['total_time'] . "\n\n";
}

echo "Tempo global da simulação: $globalTime\n";
