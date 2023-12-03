<?php

namespace Chorume\Repository;

use Chorume\Repository\User;
use Chorume\Repository\Event;
use Chorume\Repository\RouletteBet;
use Chorume\Repository\UserCoinHistory;
use Discord\Parts\Guild\Role;

class Roulette extends Repository
{
    public const OPEN = 1;
    public const CLOSED = 2;
    public const CANCELED = 3;
    public const PAID = 4;

    public const LABEL = [
        self::OPEN => 'Aberto',
        self::CLOSED => 'Fechado',
        self::CANCELED => 'Cancelado',
        self::PAID => 'Pago',
    ];

    public const LABEL_LONG = [
        self::OPEN => 'Aberto para apostas',
        self::CLOSED => 'Fechado para apostas',
        self::CANCELED => 'Cancelado',
        self::PAID => 'Apostas pagas',
    ];

    public const GREEN = 1;
    public const BLACK = 2;
    public const RED = 3;


    public const LABEL_CHOICE = [
        self::GREEN => 'Escolha Verde!',
        self::BLACK => 'Escolha Preto!',
        self::RED => 'Escolha Vermelho!',
    ];

    public function __construct(
        $db,
        protected RouletteBet|null $rouletteBetRepository = null,
        protected UserCoinHistory|null $userCoinHistoryRepository = null
    ) {
        $this->rouletteBetRepository = $rouletteBetRepository ?? new RouletteBet($db);
        $this->userCoinHistoryRepository = $userCoinHistoryRepository ?? new UserCoinHistory($db);
        parent::__construct($db);
    }

    public function createEvent(string $eventName)
    {

        $createEvent = $this->db->query('INSERT INTO roulette (status, description) VALUES (?, ?)', [
            ['type' => 'i', 'value' => self::OPEN],
            ['type' => 's', 'value' => $eventName],
        ]);
        return $createEvent;
    }

    public function close(int $eventId)
    {
        $data = [
            ['type' => 's', 'value' => self::CLOSED],
            ['type' => 'i', 'value' => $eventId],
        ];

        $createEvent = $this->db->query('UPDATE roulette SET status = ? WHERE id = ?', $data);

        return $createEvent;
    }
    public function finish(int $eventId)
    {
        $data = [
            ['type' => 's', 'value' => self::PAID],
            ['type' => 'i', 'value' => $eventId],
        ];

        $createEvent = $this->db->query('UPDATE roulette SET status = ? WHERE id = ?', $data);

        return $createEvent;
    }
    public function listEventsOpen()
    {
        $results = $this->listEventsByStatus([self::OPEN]);

        if (empty($results)) {
            return [];
        }

        return $this->normalizeRoulette($results);
    }

    public function listEventsClosed()
    {
        $results = $this->listEventsByStatus([self::CLOSED]);

        if (empty($results)) {
            return [];
        }

        return $this->normalizeRoulette($results);
    }

    public function listEventsByStatus(int|array $status)
    {
        $status = is_array($status) ? implode(',', $status) : $status;

        $results = $this->db->select("
        SELECT
            id AS roulette_id,
            description AS description,
            status AS status
        FROM roulette
        WHERE status IN (?)
    ", [
        [ 'type' => 's', 'value' => $status ],
    ]);
    
    return empty($results) ? [] : $results;
    
    }

    public function normalizeRoulette(array $roulette)
    {
        return array_reduce($roulette, function ($acc, $item) {
    
            if (!is_array($acc)) {
                $acc = [];
            }
    
            if (($subItem = array_search($item['description'], array_column($acc, 'description'))) === false) {
                $acc[] = [
                    'roulette_id' => $item['roulette_id'],
                    'description' => $item['description'],
                    'status' => $item['status'],
                ];
    
                return $acc;
            }
        }, []);
    }
    public function getRouletteById(int $rouletteId)
    {
        $result = $this->db->select(
            "SELECT * FROM roulette WHERE id = ?",
            [
                [ 'type' => 'i', 'value' => $rouletteId ]
            ]
        );

        return $result;
    }

    public function closeEvent(int $rouletteId)
    {
        $data = [
            [ 'type' => 's', 'value' => self::CLOSED ],
            [ 'type' => 'i', 'value' => $rouletteId ],
        ];

        $createEvent = $this->db->query('UPDATE roulette SET status = ? WHERE id = ?', $data);

        return $createEvent;
    }
public function payoutRoulette(int $rouletteId, $winnerChoiceKey)

    {
        $winners = [];
        $bets = $this->rouletteBetRepository->getBetsByEventId($rouletteId);
        $choiceId = $this->rouletteBetRepository->getChoiceByRouletteIdAndKey($rouletteId, $winnerChoiceKey);


        $odd = null;
        
        if($winnerChoiceKey == Roulette::GREEN)
        $odd = 14;
        else 
        $odd = 2;


        
        $this->updateRouletteWithWinner($winnerChoiceKey, $rouletteId);


        foreach ($bets as $bet) {
           $choiceKey =  $bet['choice_key'];
            if ($bet['choice_key'] !== $winnerChoiceKey) {
                continue;
            }

            $betPayout = $bet['amount'] * $odd;
            $this->userCoinHistoryRepository->create($bet['user_id'], $betPayout, 'Roulette', $rouletteId);

            $winners[] = [
                'discord_user_id' => $bet['discord_user_id'],
                'discord_username' => $bet['discord_username'],
                'choice_key' => $choiceKey,
                'earnings' => $betPayout,
            ];
        }

        return $winners;
    }


    public function updateRouletteWithWinner(int $choiceId, int $eventId)
    {
        $createEvent = $this->db->query('UPDATE roulette SET status = ?, choice = ? WHERE id = ?', [
            [ 'type' => 's', 'value' => self::PAID ],
            [ 'type' => 'i', 'value' => $choiceId, ],
            [ 'type' => 'i', 'value' => $eventId ],
        ]);

        return $createEvent;
    }

}
